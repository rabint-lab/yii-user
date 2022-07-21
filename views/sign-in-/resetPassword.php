<?php

use yii\helpers\Html;
use yii\bootstrap4\ActiveForm;

$this->context->layout = '@themeLayouts/login';
/* @var $this yii\web\View */
/* @var $form yii\widgets\ActiveForm */
/* @var $model \rabint\user\models\ResetPasswordForm */

$this->title = Yii::t('rabint', 'Reset password');
$this->params['breadcrumbs'][] = $this->title;
$linkOptions = (Yii::$app->request->isAjax)?['role'=>"modal-remote"]:[];
?>
<h2 class="ajaxModalTitle" style="display: none"><?= $this->title; ?></h2>
<div class="site-reset-password">
   
    <div class="clearfix"></div>

        <?php $form = ActiveForm::begin(['id' => 'reset-password-form']); ?>
        <?php echo Html::activeHiddenInput($model,'redirect') ?>
    <div class="row">
        <div class="col-sm-12">
            <div class="clearfix spacer"></div>
            <?= \Yii::t('rabint', 'لطفا رمز عبور جدید خود را در قسمت زیر وارد نمایید.'); ?>
        </div>
        <div class="clearfix spacer"></div>

        <div class="col-sm-12">
            <?php echo $form->field($model, 'password')->passwordInput() ?>
            <?php echo $form->field($model, 'confirm')->passwordInput() ?>
            <div class="form-group center">
                <?php echo Html::submitButton(\Yii::t('rabint', 'ذخیره'), ['class' => 'btn btn-primary']) ?>
            </div>
        </div>
    </div>
        <?php ActiveForm::end(); ?>
</div>
