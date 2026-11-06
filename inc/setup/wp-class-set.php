<?php
class Custom_Walker_Nav_Menu extends Walker_Page {
    /**
     * 产品页 ID（当前语言）
     *
     * 主导航是页面树，产品分类不是页面，所以「产品」这一项下面挂的分类
     * 由 end_el() 直接补一段 <ul class="children">，复用页面子菜单的样式与交互。
     */
    private function product_menu_page_id(): int {
        static $id = null;
        if ( $id !== null ) {
            return $id;
        }
        $id = 0;
        if ( function_exists( 'tpi_url_to_postid' ) && function_exists( 'get_url' ) ) {
            $id = (int) tpi_url_to_postid( get_url( '/products' ) );
        }
        if ( ! $id ) {
            $page = get_page_by_path( 'products' );
            $id   = $page ? (int) $page->ID : 0;
        }
        return $id;
    }

    /** 当前语言的产品分类（有内容的） */
    private function product_menu_terms(): array {
        static $terms = null;
        if ( is_array( $terms ) ) {
            return $terms;
        }
        if ( ! taxonomy_exists( 'product_cat' ) ) {
            return $terms = [];
        }
        if ( function_exists( 'tpi_get_terms_by_language' ) ) {
            $terms = (array) tpi_get_terms_by_language( 'product_cat', [
                'hide_empty' => true,
                'orderby'    => 'name',
            ] );
        } else {
            $terms = (array) get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] );
        }
        // 后台/初始化写了 _tpi_term_order 时按它排，没写的仍按名称排在后面
        $weights = [];
        foreach ( $terms as $term ) {
            $order = (int) get_term_meta( (int) $term->term_id, '_tpi_term_order', true );
            if ( $order > 0 ) {
                $weights[ (int) $term->term_id ] = $order;
            }
        }
        if ( $weights ) {
            usort( $terms, static function ( $a, $b ) use ( $weights ) {
                $oa = $weights[ (int) $a->term_id ] ?? 0;
                $ob = $weights[ (int) $b->term_id ] ?? 0;
                if ( $oa === $ob ) {
                    return strcmp( (string) $a->name, (string) $b->name );
                }
                if ( $oa === 0 ) {
                    return 1;
                }
                if ( $ob === 0 ) {
                    return -1;
                }
                return $oa <=> $ob;
            } );
        }
        return $terms;
    }

    private function is_product_menu_page( $page ): bool {
        return $page instanceof WP_Post
            && (int) $page->ID === $this->product_menu_page_id()
            && ! empty( $this->product_menu_terms() );
    }

    /**
     * 产品页收起标签前补上分类子菜单
     */
    public function end_el( &$output, $data_object, $depth = 0, $args = array() ) {
        if ( 0 === $depth && $this->is_product_menu_page( $data_object ) ) {
            // 分类页走 /products/{slug} 的重写，is_tax() 在这里为假，按 URL 判断当前分类
            $current = function_exists( 'tpi_current_term_slug' ) ? tpi_current_term_slug() : '';
            $output .= '<ul class="children product-menu-children">';
            foreach ( $this->product_menu_terms() as $term ) {
                $active  = ( $current !== '' && $current === spliceSlugLang( $term->slug ) ) ? ' current_page_item' : '';
                $output .= sprintf(
                    '<li class="page_item%s"><a href="%s">%s</a></li>',
                    $active,
                    esc_url( (string) tpi_term_url( $term, 'products' ) ),
                    esc_html( $term->name )
                );
            }
            $output .= '</ul>';
        }
        parent::end_el( $output, $data_object, $depth, $args );
    }

    /**
     * 子菜单容器：核心 Walker_Page 输出的是 <ul class='children'>（单引号），
     * 这里改成双引号，和页面上其余标签统一。
     */
    public function start_lvl( &$output, $depth = 0, $args = array() ) {
        if ( isset( $args['item_spacing'] ) && 'preserve' === $args['item_spacing'] ) {
            $t = "\t";
            $n = "\n";
        } else {
            $t = '';
            $n = '';
        }
        $indent = str_repeat( $t, $depth );
        $output .= "{$n}{$indent}<ul class=\"children\">{$n}";
    }

    public function start_el( &$output, $data_object, $depth = 0, $args = array(), $current_object_id = 0 ) {
        // Restores the more descriptive, specific name for use within this method.
        global $article_page_map_uri_list, $post;
        $page = $data_object;

        $current_page_id = $current_object_id;

        if ( isset( $args['item_spacing'] ) && 'preserve' === $args['item_spacing'] ) {
            $t = "\t";
            $n = "\n";
        } else {
            $t = '';
            $n = '';
        }
        if ( $depth ) {
            $indent = str_repeat( $t, $depth );
        } else {
            $indent = '';
        }

        $css_class = array( 'page_item', 'page-item-' . $page->ID );

        if ( isset( $args['pages_with_children'][ $page->ID ] ) || $this->is_product_menu_page( $page ) ) {
            $css_class[] = 'page_item_has_children';
        }

        if ( ! empty( $current_page_id ) ) {
            $_current_page = get_post( $current_page_id );

            if ( $_current_page && in_array( $page->ID, $_current_page->ancestors, true ) ) {
                $css_class[] = 'current_page_ancestor';
            }

            if ( $page->ID === (int) $current_page_id ) {
                $css_class[] = 'current_page_item';
            } elseif ( $_current_page && $page->ID === $_current_page->post_parent ) {
                $css_class[] = 'current_page_parent';
            }
        } elseif ( (int) get_option( 'page_for_posts' ) === $page->ID ) {
            $css_class[] = 'current_page_parent';
        }

        $active_id = $page->ID;
        if ($post) {
            $post_id = $post->ID;
            $parent_post_id = $post->post_parent;
            $uri = $article_page_map_uri_list[$post->post_type] ?? null;

            if (!$uri) {
                if ($post_id == $active_id || $parent_post_id == $active_id) {
                    $css_class[] = 'page_item_active_route';
                }
            } else {
                $parent_id = tpi_url_to_postid(get_url($uri));
                if ($parent_id == $active_id) {
                    $css_class[] = 'page_item_active_route';
                }
            }
        }

        /**
         * Filters the list of CSS classes to include with each page item in the list.
         *
         * @since 2.8.0
         *
         * @see wp_list_pages()
         *
         * @param string[] $css_class       An array of CSS classes to be applied to each list item.
         * @param WP_Post  $page            Page data object.
         * @param int      $depth           Depth of page, used for padding.
         * @param array    $args            An array of arguments.
         * @param int      $current_page_id ID of the current page.
         */
        $css_classes = implode( ' ', apply_filters( 'page_css_class', $css_class, $page, $depth, $args, $current_page_id ) );
        $css_classes = $css_classes ? ' class="' . esc_attr( $css_classes ) . '"' : '';

        if ( '' === $page->post_title ) {
            /* translators: %d: ID of a post. */
            $page->post_title = sprintf( __( '#%d (no title)' ), $page->ID );
        }

        $args['link_before'] = empty( $args['link_before'] ) ? '' : $args['link_before'];
        $args['link_after']  = empty( $args['link_after'] ) ? '' : $args['link_after'];

        $atts                 = array();
        $atts['href']         = get_permalink( $page->ID );
        $atts['aria-current'] = ( $page->ID === (int) $current_page_id ) ? 'page' : '';

        /**
         * Filters the HTML attributes applied to a page menu item's anchor element.
         *
         * @since 4.8.0
         *
         * @param array $atts {
         *     The HTML attributes applied to the menu item's `<a>` element, empty strings are ignored.
         *
         *     @type string $href         The href attribute.
         *     @type string $aria-current The aria-current attribute.
         * }
         * @param WP_Post $page            Page data object.
         * @param int     $depth           Depth of page, used for padding.
         * @param array   $args            An array of arguments.
         * @param int     $current_page_id ID of the current page.
         */
        $atts = apply_filters( 'page_menu_link_attributes', $atts, $page, $depth, $args, $current_page_id );

        $attributes = '';
        foreach ( $atts as $attr => $value ) {
            if ( is_scalar( $value ) && '' !== $value && false !== $value ) {
                $value       = ( 'href' === $attr ) ? esc_url( $value ) : esc_attr( $value );
                $attributes .= ' ' . $attr . '="' . $value . '"';
            }
        }

        $output .= $indent . sprintf(
                '<li%s><a%s>%s%s%s</a>',
                $css_classes,
                $attributes,
                $args['link_before'],
                /** This filter is documented in wp-includes/post-template.php */
                apply_filters( 'the_title', $page->post_title, $page->ID ),
                $args['link_after']
            );
        if ( isset( $args['pages_with_children'][ $page->ID ] ) || $this->is_product_menu_page( $page ) )  {
            $output .= $args['children_link_after'];
        }

        if ( ! empty( $args['show_date'] ) ) {
            if ( 'modified' === $args['show_date'] ) {
                $time = $page->post_modified;
            } else {
                $time = $page->post_date;
            }

            $date_format = empty( $args['date_format'] ) ? '' : $args['date_format'];
            $output     .= ' ' . mysql2date( $date_format, $time );
        }
    }
}