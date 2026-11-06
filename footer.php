<?php
global $language_list, $media_info_list, $page_info_settings, $lang, $current_lang_json, $host_url, $whatsapp_url;

$copy_success = $current_lang_json["footer"]["copy-success"] ?? '';
?>
</main>
<footer class="footer" role="contentinfo" aria-label="Website copyright information">
    <address class="copy-box" aria-label="Contact Us">
        <div class="contact-us"><?php echo $current_lang_json["footer"]["contact-us"];?></div>
        <div class="info-box">
            <div class="footer-contact-col">
            <ul class="media-contact-box" aria-label="Follow us on social media">
                <?php foreach ($media_info_list as $code => $item):?>
                    <li>
                        <a class="point-to" href="<?php echo $item["url"]; ?>" target="_blank" aria-label="<?php echo $item["name"]; ?>" rel="noopener noreferrer nofollow">
                            <img src="<?php echo $item["icon_url"] ?>" alt="<?php echo "Media ". $item["name"];?>" aria-hidden="true">
                            <span aria-label="Social media handles"><?php echo $item["name"]; ?></span>
                        </a>
                    </li>
                <?php endforeach;?>
            </ul>
            <ul class="common-contact-box" aria-label="Common Contact Us">
                <li class="mail-info copy-info-box copy-email-element" data-message="<?php echo $copy_success. ': '. ($page_info_settings['email'] ?? '');?>">
                    <a class="info-item" rel="nofollow" target="_blank" href="mailto:<?php echo $page_info_settings['email'] ?? '';?>" aria-label="Email Contact">
                        <p class="info-item-content">
                            <?php /* 原来不传色 → 取的是 #ffffff 母版，压在白色卡片上等于没有图标；和下面三行统一成 4a5866 */ ?>
                            <img src="<?php echo esc_url(tpi_svg_url('Email', '4a5866')); ?>" alt="Mail icon" aria-hidden="true">
                            <strong class="label"><?php echo $current_lang_json["footer"]["email-title"];?>: </strong>
                            <span class="info-desc copy-info-desc"><?php echo $page_info_settings['email'] ?? '';?></span>
                        </p>
                        <p class="info-item-desc">
                            <?php echo $current_lang_json["footer"]["copy-email"] ?? '';?>
                        </p>
                    </a>
                </li>
                <li class="telephone-info copy-info-box" data-message="<?php echo $copy_success. ': '. ($page_info_settings['telephone'] ?? '');?>">
                    <a class="info-item" rel="nofollow" href="tel:<?php echo $page_info_settings['telephone'] ?? '';?>" aria-label="Call us at <?php echo $page_info_settings['telephone'] ?? '';?>">
                        <img src="<?php echo esc_url(tpi_svg_url('Telephone', '4a5866')); ?>" alt="Telephone icon" aria-hidden="true">
                        <strong class="label"><?php echo $current_lang_json["footer"]["telephone-title"] ?? '';?>: </strong>
                        <span class="info-desc copy-info-desc"><?php echo $page_info_settings['telephone'] ?? '';?></span>
                    </a>
                </li>
                <li class="whats-app-info">
                    <a class="info-item" rel="noopener noreferrer nofollow" target="_blank" href="<?php echo $whatsapp_url;?>"  aria-label="Call us at <?php echo $page_info_settings['whats_app'] ?? '';?>">
                        <img src="<?php echo esc_url(tpi_svg_url('WhatsApp', '4a5866')); ?>" alt="WhatsApp icon" aria-hidden="true">
                        <strong class="label"><?php echo $current_lang_json["footer"]["whats-app-title"] ?? '';?>: </strong>
                        <span class="info-desc"><?php echo $page_info_settings['whats_app'] ?? '';?></span>
                    </a>
                </li>
                <li class="address-info copy-info-box" data-message="<?php echo $copy_success;?>">
                    <p class="info-item">
                        <img src="<?php echo esc_url(tpi_svg_url('Address', '4a5866')); ?>" alt="Address icon" aria-hidden="true">
                        <strong class="label"><?php echo $current_lang_json["footer"]["address-title"];?>:</strong>
                        <span class="info-desc copy-info-desc"><?php echo $page_info_settings['address'] ?? '';?></span>
                    </p>
                </li>
            </ul>
            </div>
            <div class="mail-box" role="complementary">
                <form class="inquiry mail-wrap" aria-label="Email Inquiry Form"
                      data-api="<?php echo esc_url(tpi_api_url('inquiry')); ?>"
                      data-lang="<?php echo esc_attr(tpi_current_language()); ?>"
                      data-success-text="<?php echo $current_lang_json["footer"]["message-success"];?>"
                      data-error-text="<?php echo $current_lang_json["footer"]["message-error"];?>"
                >
                    <div class="email-title-box">
                        <div class="top-left-inquiry-wrap">
                            <img src="<?php echo esc_url(tpi_asset('/svg/Message.svg')); ?>" alt="Inquire title icon" aria-hidden="true">
                            <span><?php echo $current_lang_json["functional"]["inquiry"]["title"];?></span>
                        </div>
                    </div>
                    <label title="Please enter your name company">
                        <input type="text" name="company" placeholder="company" autocomplete="off">
                    </label>
                    <label title="Please enter your name or company name">
                        <input type="text" name="name" placeholder="<?php echo $current_lang_json["footer"]["name-placeholder"];?>" autocomplete="off">
                        <img src="<?php echo esc_url(tpi_svg_url('Identity', '8a97a3')); ?>" alt="name or company name" aria-hidden="true">
                    </label>
                    <label title="Please enter your email">
                        <input type="text" name="email" placeholder="<?php echo $current_lang_json["footer"]["email-placeholder"];?>" autocomplete="off">
                        <img src="<?php echo esc_url(tpi_svg_url('Email', '8a97a3')); ?>" alt="name or company name" aria-hidden="true">
                    </label>
                    <label title="Please enter your name telephone">
                        <input type="text" name="telephone" placeholder="<?php echo $current_lang_json["footer"]["telephone-placeholder"];?>" autocomplete="off">
                        <img src="<?php echo esc_url(tpi_svg_url('Telephone', '8a97a3')); ?>" alt="name or company name" aria-hidden="true">
                    </label>
                    <label title="Please enter your message or inquiry">
                        <textarea name="inquiry" placeholder="<?php echo $current_lang_json["footer"]["inquiry-placeholder"];?>" autocomplete="off"></textarea>
                        <img src="<?php echo esc_url(tpi_svg_url('FillIn', '8a97a3')); ?>" alt="name or company name" aria-hidden="true">
                    </label>
                    <button class="inquiry-submit" type="submit" aria-label="Submit your inquiry via email">
                        <?php echo $current_lang_json["footer"]["send"];?>
                    </button>
                </form>
            </div>
        </div>
        <p class="copy-info" aria-label="Copyright Information">
            <span><?php echo $page_info_settings['copy_info'] ?? '';?></span>
            <span> | </span>
            <a href="<?php echo get_url('privacy-policy');?>"><?php echo get_the_title(tpi_url_to_postid(get_url('privacy-policy')));?></a>
            <span> | </span>
            <a href="<?php echo $host_url.'/sitemap.xml';?>">XML</a>
        </p>
    </address>
    <ul class="menu-footer" aria-label="Foot menu list">
        <li class="<?php if (is_current_route_parent(go_url("/home"))) { echo 'mobile-page_item_active_route'; }  ; ?>">
            <a href="<?php echo go_url("/home"); ?>">
                <?php echo tpi_nav_icon('home'); ?>
                <span class="title-menu"><?php echo $current_lang_json["footer"]["home"];?></span>
            </a>
        </li>
        <li class="<?php if (is_current_route_parent(go_url("/products"))) { echo 'mobile-page_item_active_route'; }  ; ?>">
            <a href="<?php echo go_url("/products"); ?>">
                <?php echo tpi_nav_icon('products'); ?>
                <span class="title-menu"><?php echo $current_lang_json["footer"]["products"];?></span>
            </a>
        </li>
        <li>
            <button class="visible-inquiry-btn" aria-label="Visible inquiry box">
                <?php echo tpi_nav_icon('message'); ?>
                <span class="title-menu"><?php echo $current_lang_json["footer"]["message"];?></span>
            </button>
        </li>
        <li class="<?php if (is_current_route_parent(go_url("/about-us"))) { echo 'mobile-page_item_active_route'; }  ; ?>">
            <a href="<?php echo go_url("/about-us"); ?>">
                <?php echo tpi_nav_icon('about-us'); ?>
                <span class="title-menu"><?php echo $current_lang_json["footer"]["about-us"];?></span>
            </a>
        </li>
        <li class="<?php if (is_current_route_parent(go_url("/contact"))) { echo 'mobile-page_item_active_route'; }  ; ?>">
            <a href="<?php echo go_url("/contact"); ?>">
                <?php echo tpi_nav_icon('contact'); ?>
                <span class="title-menu"><?php echo $current_lang_json["footer"]["contact"];?></span>
            </a>
        </li>
    </ul>
</footer>
<?php
get_fixed_functional_box();
wp_footer();
?>
</body>
</html>