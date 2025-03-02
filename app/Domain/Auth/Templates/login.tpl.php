<?php
foreach ($__data as $var => $val) {
    $$var = $val; // necessary for blade refactor
}
$redirectUrl = $tpl->get('redirectUrl');
?>

<?php $tpl->dispatchTplEvent('beforePageHeaderOpen'); ?>
<div class="pageheader">
    <?php $tpl->dispatchTplEvent('afterPageHeaderOpen'); ?>
    <div class="pagetitle">
        <h1><?php echo $tpl->language->__('headlines.login'); ?></h1>
    </div>
    <?php $tpl->dispatchTplEvent('beforePageHeaderClose'); ?>
</div>
<?php $tpl->dispatchTplEvent('afterPageHeaderClose'); ?>

<div class="regcontent">
    <?php $tpl->dispatchTplEvent('afterRegcontentOpen'); ?>
    <?php echo $tpl->displayInlineNotification(); ?>

    <?php if ($tpl->get('noLoginForm') === false) { ?>
        <form id="login" action="<?= BASE_URL.'/auth/login'?>" method="post">
            <?php $tpl->dispatchTplEvent('afterFormOpen'); ?>
        <input type="hidden" name="redirectUrl" value="<?php echo $redirectUrl; ?>" />

        <div class="">
            <label for="username">Email</label>
            <input type="text" name="username" id="username" class="form-control" placeholder="<?php echo $tpl->language->__($tpl->get('inputPlaceholder')); ?>" value=""/>
        </div>
        <div class="">
            <label for="password">Password</label>
            <input type="password" name="password" id="password" autocomplete="off" class="form-control" placeholder="<?php echo $tpl->language->__('input.placeholders.enter_password'); ?>" value=""/>
            <div class="forgotPwContainer">
                <a href="<?= BASE_URL ?>/auth/resetPw" class="forgotPw"><?php echo $tpl->language->__('links.forgot_password'); ?></a>
            </div>
        </div>
            <?php $tpl->dispatchTplEvent('beforeSubmitButton'); ?>
        <div class="">
            <input type="submit" name="login" value="<?php echo $tpl->language->__('buttons.login'); ?>" class="btn btn-primary"/>
        </div>
        <div>
        </div>
            <?php $tpl->dispatchTplEvent('beforeFormClose'); ?>

    </form>
    <?php } else {
        echo $tpl->language->__('text.no_login_form');
        ?><br /><br />
    <?php }// if disableLoginForm?>

    <?php if ($tpl->get('oidcEnabled')) { ?>

        <?php $tpl->dispatchTplEvent('beforeOidcButton'); ?>

        <div class="">
            <div style="margin-top:20px; border-bottom:1px solid #ccc; with:100%; height:10px; overflow:show; text-align:center; margin-bottom:40px;">
                <p style="text-align:center; display:inline-block; background:var(--secondary-background); padding:0px 5px;"><?php echo $tpl->language->__('label.or_login_with'); ?></p>
            </div>
            <a href="<?= BASE_URL ?>/oidc/login" style="width:100%;" class="btn btn-primary">
            <?php echo $tpl->language->__('buttons.oidclogin'); ?>
            </a>
        </div>
    <?php } ?>

    <?php $tpl->dispatchTplEvent('beforeRegcontentClose'); ?>
</div>
