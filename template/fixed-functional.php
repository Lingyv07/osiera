<?php
global $media_info_list, $page_info_settings, $current_lang_json, $whatsapp_url;

$copy_success = $current_lang_json["footer"]["copy-success"] ?? '';
?>
<div class="fixed-box" role="complementary">
    <button class="progress-wrap point-to" aria-label="Back to top">
        <svg class="progress-circle svg-content" width="100%" height="100%" viewBox="-1 -1 102 102" aria-hidden="true" focusable="false">
            <path d="M50,1 a49,49 0 0,1 0,98 a49,49 0 0,1 0,-98" style="transition: stroke-dashoffset 10ms linear; stroke-dasharray: 307.919, 307.919; stroke-dashoffset: 307.919;"></path>
        </svg>
    </button>
    <div id="toast-message" class="toast-message" aria-hidden="true">
        <img class="toast-success" src="<?php echo esc_url(tpi_svg_url('Selected', '239433')); ?>" aria-hidden="true" alt="success">
        <img class="toast-error" src="<?php echo esc_url(tpi_svg_url('Wrong', 'd51414')); ?>" aria-hidden="true" alt="error">
        <span class="toast-text"></span>
    </div>
    <div class="visible-inquiry" aria-hidden="true">
        <span class="visible-inquiry-point-message">
            <?php echo $current_lang_json["functional"]["inquiry"]["point-message"];?>
        </span>
        <div class="visible-inquiry-icon">
            <img src="<?php echo esc_url(tpi_asset('/svg/Message.svg')); ?>" alt="Inquire title icon" aria-hidden="true">
        </div>
    </div>
    <div class="fixed-mail-box" role="complementary">
        <div class="top-inquiry-wrap">
            <div class="top-left-inquiry-wrap">
                <img src="<?php echo esc_url(tpi_asset('/svg/Message.svg')); ?>" alt="Inquire title icon" aria-hidden="true">
                <span><?php echo $current_lang_json["functional"]["inquiry"]["title"];?></span>
            </div>
            <button class="close-inquiry close-icon"></button>
        </div>
        <form class="inquiry mail-wrap" aria-label="Email Inquiry Form"
              data-api="<?php echo esc_url(tpi_api_url('inquiry')); ?>"
              data-lang="<?php echo esc_attr(tpi_current_language()); ?>"
              data-success-text="<?php echo $current_lang_json["footer"]["message-success"];?>"
              data-error-text="<?php echo $current_lang_json["footer"]["message-error"];?>"
        >
            <label title="Please enter your name company">
                <input type="text" name="company" placeholder="company" autocomplete="off">
            </label>
            <label title="Please enter your name or company name">
                <input type="text" name="name" placeholder="<?php echo $current_lang_json["footer"]["name-placeholder"];?>" autocomplete="off">
                <img src="<?php echo esc_url(tpi_svg_url('Identity', '8a97a3')); ?>" alt="Name icon" aria-hidden="true">
            </label>
            <label title="Please enter your email">
                <input type="text" name="email" placeholder="<?php echo $current_lang_json["footer"]["email-placeholder"];?>" autocomplete="off">
                <img src="<?php echo esc_url(tpi_svg_url('Email', '8a97a3')); ?>" alt="Email icon" aria-hidden="true">
            </label>
            <label title="Please enter your name telephone">
                <input type="text" name="telephone" placeholder="<?php echo $current_lang_json["footer"]["telephone-placeholder"];?>" autocomplete="off">
                <img src="<?php echo esc_url(tpi_svg_url('Telephone', '8a97a3')); ?>" alt="Telephone icon" aria-hidden="true">
            </label>
            <label title="Please enter your message or inquiry">
                <textarea name="inquiry" placeholder="<?php echo $current_lang_json["footer"]["inquiry-placeholder"];?>" autocomplete="off"></textarea>
                <img src="<?php echo esc_url(tpi_svg_url('FillIn', '8a97a3')); ?>" alt="FillIn icon" aria-hidden="true">
            </label>
            <button class="inquiry-submit" type="submit" aria-label="Submit your inquiry via email">
                <?php echo $current_lang_json["footer"]["send"];?>
            </button>
        </form>
    </div>
    <div class="address-tel-email">
        <div class="telephone-wrap copy-info-box" data-message="<?php echo $copy_success. ': '. ($page_info_settings['telephone'] ?? '');?>">
            <a class="call-now" rel="nofollow" href="tel:<?php echo $page_info_settings['telephone'] ?? '';?>" aria-label="Call us at <?php echo $page_info_settings['telephone'] ?? '';?>">
                <img src="<?php echo esc_url(tpi_svg_url('Telephone')); ?>" alt="Telephone icon" aria-hidden="true">
                <span class="info-desc copy-info-desc"><?php echo $page_info_settings['telephone'] ?? '';?></span>
            </a>
        </div>
        <div class="email-wrap copy-info-box copy-email-element" data-message="<?php echo $copy_success. ': '. ($page_info_settings['email'] ?? '');?>">
            <a rel="nofollow" target="_blank" href="mailto:<?php echo $page_info_settings['email'] ?? '';?>"  aria-label="Email Contact">
                <img src="<?php echo esc_url(tpi_svg_url('Email')); ?>" alt="Mail icon" aria-hidden="true">
                <span class="info-desc copy-info-desc"><?php echo $page_info_settings['email'] ?? '';?></span>
            </a>
        </div>
        <div class="whats-app-wrap">
            <a rel="noopener noreferrer nofollow" target="_blank" href="<?php echo $whatsapp_url;?>"  aria-label="Call us at <?php echo $page_info_settings['whats_app'] ?? '';?>">
                <img src="<?php echo esc_url(tpi_svg_url('WhatsApp')); ?>" alt="WhatsApp icon" aria-hidden="true">
                <span class="info-desc"><?php echo $page_info_settings['whats_app'] ?? '';?></span>
            </a>
        </div>
    </div>
    <?php
    // Cookie 提示条只在后台「监控管理 → 需要访客授权 Cookie」打开时输出；
    // 关闭（默认授权）时前台不打扰访客，脚本直接加载。
    if (function_exists('tpi_monitor_consent_required') && tpi_monitor_consent_required()) {
        ?>
        <div id="cookie-policy" class="cookie-policy">
            <div class="cookie-policy-wrap">
                <div class="cookie-policy-header">
                    <div class="cookie-policy-title"><?php echo $current_lang_json["cookie"]['cookie-policy-title'] ?? '';?></div>
                    <span class="cookie-policy-close" title="Close"></span>
                </div>
                <div class="cookie-policy-content">
                    <?php echo $current_lang_json["cookie"]['cookie-policy-content'] ?? '';?>
                    <a href="<?php echo get_url('privacy-policy');?>" rel="nofollow" class="cookie-policy-more"><?php echo $current_lang_json["cookie"]['cookie-policy-more'] ?? '';?></a>
                </div>
                <div class="cookie-policy-footer">
            <span class="cookie-policy-reject">
                <em title="Reject - Click here to reject cookies" onclick="rejectCookies()"><?php echo $current_lang_json["cookie"]['cookie-policy-reject-text'] ?? '';?></em><?php echo $current_lang_json["cookie"]['cookie-policy-reject-des'] ?? '';?>
            </span>
                    <button class="cookie-policy-btn" onclick="acceptCookies()"><?php echo $current_lang_json["cookie"]['cookie-policy-btn'] ?? '';?></button>
                </div>
            </div>
        </div>
        <?php
    }
    ?>
</div>
