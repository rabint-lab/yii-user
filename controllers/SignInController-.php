<?php

namespace rabint\user\controllers;

use app\commands\SendEmailCommand;
use rabint\helpers\str;
use rabint\notify\models\Notification;
use rabint\user\models\GetResetToken;
use rabint\user\models\LoginForm;
use rabint\user\models\PasswordResetRequestForm;
use rabint\user\models\ResetPasswordForm;
use rabint\user\models\SignupFastForm;
use rabint\user\models\SignupForm;
use rabint\user\models\User;
use rabint\user\models\UserToken;
use Yii;
use yii\base\Exception;
use yii\base\InvalidParamException;
use yii\filters\AccessControl;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\widgets\ActiveForm;
use rabint\user\models\InviteForm;

/**
 * Class SignInController
 * @package rabint\user\controllers
 * @author Eugene Terentev <eugene@terentev.net>
 */
class SignInController extends \rabint\controllers\DefaultController
{

    static $captchaLoginTryCount = 3;
    var $layout = '@theme/views/layouts/login';

    /**
     * @return array
     */
    public function actions()
    {
        return [
            'oauth' => [
                'class' => 'yii\authclient\AuthAction',
                'successCallback' => [$this, 'successOAuthCallback']
            ]
        ];
    }

    /**
     * @return array
     */
    public function behaviors()
    {
        $ret = parent::behaviors();
        return $ret + [
                'access' => [
                    'class' => AccessControl::className(),
                    'rules' => [
                        [
                            'actions' => [
                                'signup',
                                'signup-fast',
                                'login',
                                'request-password-reset',
                                'reset-password',
                                'get-reset-token',
                                'get-active-token',
                                'oauth',
                                'activation',
                                'reject-invite',
                                'accept-invite',
                                'invite'
                            ],
                            'allow' => true,
                            'roles' => ['?']
                        ],
                        [
                            'actions' => [
                                'signup',
                                'signup-fast',
                                'login',
                                'request-password-reset',
                                'reset-password',
                                'oauth',
                                'activation',
                                'reject-invite',
                                'accept-invite',
                                'invite'

                            ],
                            'allow' => false,
                            'roles' => ['@'],
                            'denyCallback' => function () {
                                return Yii::$app->controller->redirect(\rabint\helpers\uri::dashboardRoute());
                            }
                        ],
                        [
                            'actions' => ['logout', 'change-password', 'email-validation'],
                            'allow' => true,
                            'roles' => ['@'],
                        ]
                    ]
                ],
                //            'verbs' => [
                //                'class' => VerbFilter::className(),
                //                'actions' => [
                //                    'logout' => ['post']
                //                ]
                //            ]
            ];
    }

//    public function init()
//    {
//        $panelTheme = config('panelThemePath', '@rabint/themes/basic');
//        if (!empty($panelTheme)) {
//            Yii::setAlias('@theme', $panelTheme);
//            Yii::setAlias('@themeLayouts', $panelTheme . '/views/layouts');
//
//            \Yii::$app->view->theme = new \yii\base\Theme([
//                'pathMap' => ['@app/views' => $panelTheme . '/views'],
//            ]);
//        }
//        return parent::init(); // TODO: Change the autogenerated stub
//    }

    /**
     * @return array|string|Response
     */
    public function actionLogin($redirect = "remember")
    {
        //        Yii::$app->session->set('login_try_count', 0);
        if($redirect=="remember"){
            $redirect = Url::to(\rabint\helpers\uri::dashboardRoute(),true);
        }
        $this->layout = '@theme/views/layouts/common';
        $model = new LoginForm();
        $loginTryCount = Yii::$app->session->get('login_try_count', 0);
        if ($loginTryCount < self::$captchaLoginTryCount + 1) {
            $model->scenario = LoginForm::SCENARIO_FIRST_TRY;
        } else {
            $model->scenario = LoginForm::SCENARIO_DEFAULT;
        }
        if (Yii::$app->request->isAjax && Yii::$app->request->isPost) {
            $model->load(Yii::$app->request->post());
            Yii::$app->response->format = Response::FORMAT_JSON;
            return ActiveForm::validate($model);
        }
        $res = $model->load(Yii::$app->request->post());

        //        var_dump($res);

        //check user is inactive
        if(Yii::$app->request->post())
        {
            $model->load(Yii::$app->request->post());
            $model->identity = str::formatCellphone($model->identity);
            $check = User::find()->where([
                'or', ['username' => $model->identity], ['email' => $model->identity]
            ])->one();
            //var_dump($check->username);
            //var_dump($check->email);
            //var_dump($check->status);
            //exit;
            if($check && in_array($check->status,[
                    User::STATUS_NOT_ACTIVE
                ]))
            {

                $model->addError('username',\Yii::t('rabint', 'تاییدیه حساب شما صورت نگرفته است . '));
                $model->addError('identity',\Yii::t('rabint', 'تاییدیه حساب شما صورت نگرفته است . '));
                $sendr = new PasswordResetRequestForm();
                $sendr->username = $model->identity;
                $sendr->sendActivation();
                Yii::$app->session->setFlash(
                    'warning',
                    \Yii::t('rabint', 'کاربر گرامی!.')
                    . "<br/>"
                    . \Yii::t('rabint', 'تاییدیه حساب شما صورت نگرفته است . ')
                );
                $redirect = Url::to(['login'],true);
                return $this->redirect(['get-active-token',
                    'cell' => $model->identity,
                    'redirect' => $redirect
                ]);
            }

        }


        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            if (!empty($model->redirect)) {
                $redirect = $model->redirect;
            }
            /**
             * check user must change password
             */
            $aset_must_changed_password = \rabint\helpers\user::profile()->aset_must_changed_password;
            if (!empty($aset_must_changed_password)) {
                Yii::$app->session->setFlash(
                    'warning',
                    \Yii::t('rabint', 'کاربر گرامی!.')
                    . "<br/>"
                    . \Yii::t('rabint', 'جهت بر خوردای از امنیت کافی لطفا رمز عبور خود را تغییر دهید. با سپاس')
                );
                return $this->redirect(['change-password', 'redirect' => $redirect]);
            }
            // fill last entry fiell
            $u = $model->getUser();
            $u->logged_at = time();
            $u->save(false);
            //Yii::$app->badge->add(\rabint\helpers\user::id(), 'login', 86400);
            return $this->doRedirect($redirect);
        }
        if ($loginTryCount < self::$captchaLoginTryCount) {
            $model->scenario = LoginForm::SCENARIO_FIRST_TRY;
        } else {
            $model->scenario = LoginForm::SCENARIO_DEFAULT;
        }

        $model->redirect = (empty($redirect)) ? \rabint\helpers\uri::referrer() : $redirect;

        \rabint\helpers\uri::remember($model->redirect);


        if (Yii::$app->request->isAjax) {
            return $this->renderAjax('login', [
                'model' => $model
            ]);
        }
        return $this->render('login', [
            'model' => $model
        ]);
    }

    protected function doRedirect($redirect = null)
    {
        return \rabint\helpers\uri::redirectTo($redirect, true);
    }

    /**
     * @return Response
     */
    public function actionLogout($redirect = null)
    {
        Yii::$app->user->logout();
        return $this->doRedirect($redirect);
    }

    /**
     * @return string|Response
     */
    public function actionSignupOld($redirect = null)
    {
        $model = new SignupForm();
        if ($model->load(Yii::$app->request->post())) {
            if (!empty($model->redirect)) {
                $redirect = $model->redirect;
            }

            $user = $model->signup();
            if ($user) {
                if ($model->shouldBeActivated()) {

                    if (\rabint\user\Module::$cellBaseAuth) {
                        Yii::$app->session->setFlash('success', \Yii::t(
                            'rabint',
                            'لینک فعال سازی برای شما پیامک شد.'
                        ));
                        return $this->redirect(['get-active-token', 'cell' => $user->username, 'redirect' => $redirect]);
                    } else {
                        Yii::$app->session->setFlash('success', \Yii::t(
                            'rabint',
                            'لینک فعال سازی به ایمیل شما ارسال شد. لطفا پوشه inbox و یا spam ایمیل خود را بررسی نمایید'
                        ));
                        return $this->doRedirect($redirect);
                    }
                } else {
                    Yii::$app->session->setFlash(
                        'success',
                        \Yii::t('rabint', 'کاربر گرامی!‌ ثبت نام شما انجام شد. لطفا با رجوع به پروفایل ، اطلاعات شخصی خود را کامل نمایید')
                    );
                    Yii::$app->getUser()->login($user);
                    return $this->doRedirect($redirect);
                }
                return $this->doRedirect($redirect);
            }
        }
        $model->redirect = (empty($redirect)) ? \rabint\helpers\uri::referrer() : $redirect;
        return $this->render('signup', [
            'model' => $model
        ]);
    }

    /**
     * @return string|Response
     */
    public function actionSignup($redirect = null)
    {
        $model = new SignupFastForm();
        if ($model->load(Yii::$app->request->post())) {
            if (!empty($model->redirect)) {
                $redirect = $model->redirect;
            }
            $user = $model->signup();
            if ($user) {
                if ($model->shouldBeActivated()) {

                    if (\rabint\user\Module::$cellBaseAuth) {
                        Yii::$app->session->setFlash('success', \Yii::t(
                            'rabint',
                            'لینک فعال سازی برای شما پیامک شد.'
                        ));
                        return $this->redirect(['get-active-token', 'cell' => $user->username, 'redirect' => $redirect]);
                    } else {
                        Yii::$app->session->setFlash('success', \Yii::t(
                            'rabint',
                            'لینک فعال سازی به ایمیل شما ارسال شد. لطفا پوشه inbox و یا spam ایمیل خود را بررسی نمایید'
                        ));
                        return $this->doRedirect($redirect);
                    }
                } else {
                    //send welcome message
                    $text = Yii::t('rabint', 'کاربر گرامی ثبت نام شما با موفقیت انجام گردید ');
                    $has_sent = Yii::$app->notify->send($user->id, $text, '', [
                        'priority' => Notification::MEDIA_EMAIL_AND_SMS,
                    ]);
                    Yii::$app->session->setFlash(
                        'success',
                        \Yii::t('rabint', 'کاربر گرامی!‌ ثبت نام شما انجام شد. لطفا با رجوع به پروفایل ، اطلاعات شخصی خود را کامل نمایید')
                    );
                    Yii::$app->getUser()->login($user);
                    return $this->doRedirect($redirect);
                }
                return $this->doRedirect($redirect);
            }
        }
        $model->redirect = (empty($redirect)) ? \rabint\helpers\uri::referrer() : $redirect;
        return $this->render('signup-fast', [
            'model' => $model
        ]);
    }

    public function actionActivation($token, $redirect = null)
    {
        $token = UserToken::find()
            ->byType(UserToken::TYPE_ACTIVATION)
            ->byToken($token)
            ->notExpired()
            ->one();

        if (!$token) {
            Yii::$app->session->setFlash(
                'danger',
                \Yii::t('rabint', 'کد فعال سازی شما معتبر نیست و یا منقضی شده است!')
            );
            return $this->redirect(['get-active-token', 'cell' => '', 'redirect' => $redirect]);
        }

        $user = $token->user;
        if ($user === null) {
            Yii::$app->session->setFlash(
                'danger',
                \Yii::t('rabint', 'کد فعال سازی شما معتبر نیست و یا منقضی شده است!')
            );
            return $this->redirect(['get-active-token', 'cell' => '', 'redirect' => $redirect]);
        }
        $user->updateAttributes([
            'status' => User::STATUS_ACTIVE
        ]);
        $token->delete();
        Yii::$app->getUser()->login($user);

        Yii::$app->session->setFlash(
            'success',
            \Yii::t('rabint', 'حساب کاربری شما با موفقیت فعال شد.')
        );

        return $this->doRedirect($redirect);
        //return $this->goHome();
    }

    public function actionEmailValidation($token = null)
    {
        if ($token !== null) {
            $is_new = false;
            $token = UserToken::find()
                ->byType(UserToken::TYPE_ACTIVATION)
                ->byToken($token)
                ->notExpired()
                ->one();

            if (!$token) {
                Yii::$app->session->setFlash(
                    'danger',
                    \Yii::t('rabint', 'لینک فعال سازی شما معتبر نیست و یا منقضی شده است!')
                );
                return $this->redirect(\yii\helpers\Url::home());
            }

            $user = $token->user;
            $user->userProfile->validateEmail(true);
            $token->delete();
            Yii::$app->session->setFlash('success', \Yii::t('rabint', 'ایمیل شما با موفقیت فعال شد.'));
            return $this->redirect(['/user/default/profile']);
        } else {
            if (\rabint\helpers\user::profile()->email_activated == 1) {
                Yii::$app->session->setFlash('success', \Yii::t('rabint', 'کاربر گرامی!‌ایمیل شما فعال شده است .'));
                return $this->redirect(['/user/default/profile']);
            }
            $is_new = true;
            $token = UserToken::create(
                \rabint\helpers\user::id(),
                UserToken::TYPE_ACTIVATION,
                \rabint\cheatsheet\Time::SECONDS_IN_A_DAY
            );
            try {
                Yii::$app->commandBus->handle(new SendEmailCommand([
                    'subject' => Yii::t('rabint', 'Activation email'),
                    'view' => 'activation',
                    'to' => \rabint\helpers\user::email(),
                    'params' => [
                        'url' => \yii\helpers\Url::to(
                            ['/user/sign-in/email-validation', 'token' => $token->token],
                            true
                        )
                    ]
                ]));
                //                Yii::$app->session->setFlash('success', \Yii::t('rabint', 'ایمیل ارسال شد! <br/>کاربر گرامی لینک فعال سازی برای شما ایمیل شد. لطفا ایمیل خود را بررسی نمایید'));
                Yii::$app->session->setFlash('success', \Yii::t(
                    'rabint',
                    'ایمیل ارسال شد! <br/>ایمیل فعالسازی با موفقیت ارسال گردید. لطفا پوشه inbox و spam ایمیل خود را بررسی نمایید.'
                ));
                $_SESSION['EmailValidationSent'] = true;
                return $this->redirect(['/user/default/profile']);
            } catch (\Swift_TransportException $exc) {
                Yii::$app->session->setFlash('danger', \Yii::t(
                    'rabint',
                    'خطای سیستمی! <br/> متاسفانه سیستم قادر به ارسال ایمیل نیست. لطفا بعدا تلاش نمایید'
                ));
                return $this->redirect(['/user/default/profile']);
            }
        }
        return $this->render('email-validation', [
            'is_new_token' => $is_new,
        ]);
    }

    public function actionAcceptInvite($token = null)
    {
        if ($token == null) {
            throw new BadRequestHttpException;
        }
        $is_new = false;
        $token = UserToken::find()
            ->byType(UserToken::TYPE_ACTIVATION)
            ->byToken($token)
            ->notExpired()
            ->one();

        if (!$token) {
            Yii::$app->session->setFlash('danger', \Yii::t('rabint', 'لینک دعوتنامه معتبر نیست و یا منقضی شده است!'));
            return $this->redirect(\yii\helpers\Url::home());
        }

        $user = $token->user;
        $user->userProfile->validateEmail(true);
        $user->setActive();
        $token->delete();
        Yii::$app->session->setFlash('success', \Yii::t(
            'rabint',
            'حساب کاربری شما فعال شد. می توانید با نام کاربری و رمز موجود در ایمیل دعوتنامه وارد شوید.'
        ));
        return $this->redirect(['login']);
    }

    public function actionRejectInvite($token = null)
    {
        if ($token == null) {
            throw new BadRequestHttpException;
        }
        $is_new = false;
        $token = UserToken::find()
            ->byType(UserToken::TYPE_ACTIVATION)
            ->byToken($token)
            ->notExpired()
            ->one();

        if (!$token) {
            Yii::$app->session->setFlash('danger', \Yii::t('rabint', 'لینک دعوتنامه معتبر نیست و یا منقضی شده است!'));
            return $this->redirect(\yii\helpers\Url::home());
        }

        $user = $token->user;
        $token->delete();
        $user->delete();
        Yii::$app->session->setFlash('success', \Yii::t('rabint', 'اطلاعات شما بطور کامل از این وبسایت حذف گردید'));
        return $this->redirect(['signup']);
    }

    /**
     * @return string|Response
     */
    public function actionRequestPasswordReset($redirect = null,$type='')
    {
        $model = new PasswordResetRequestForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {

            if (!empty($model->redirect)) {
                $redirect = $model->redirect;
            }

            if ($model->sendActivation()) {
                if (\rabint\user\Module::$cellBaseAuth) {
                    Yii::$app->session->setFlash('success', \Yii::t(
                        'rabint',
                        'لینک فعال سازی برای شما پیامک شد.'
                    ));
                    if($type == 'activation')
                        return $this->redirect(['get-active-token', 'cell' => $model->username, 'redirect' => $redirect]);
                    else
                        return $this->redirect(['get-reset-token', 'cell' => $model->username, 'redirect' => $redirect]);
                } else {
                    Yii::$app->session->setFlash('success', \Yii::t(
                        'rabint',
                        'لینک فعال سازی به ایمیل شما ارسال شد. لطفا پوشه inbox و یا spam ایمیل خود را بررسی نمایید'
                    ));
                    return $this->doRedirect($redirect);
                }
            } else {
                Yii::$app->session->setFlash(
                    'danger',
                    \Yii::t('rabint', 'متاسفانه امکان ارسال کد فعال سازی برای شما فراهم نیست. لطفا بعد از 2دقیقه تلاش فرمایید.')
                );
            }
        }
        $model->redirect = (empty($redirect)) ? \rabint\helpers\uri::referrer() : $redirect;
        if (\rabint\user\Module::$cellBaseAuth) {
            return $this->render('requestPasswordResetSms', [
                'model' => $model,
                'type' => $type,
            ]);
        }
        return $this->render('requestPasswordResetToken', [
            'model' => $model,
            'type' => $type,
        ]);
    }

    /**
     * @param $token
     * @return string|Response
     * @throws BadRequestHttpException
     */
    public function actionGetResetToken($cell='', $redirect = null)
    {
        $model = new GetResetToken();

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if (!empty($model->redirect)) {
                $redirect = $model->redirect;
            }

            $token = $model->checkToken();
            if (empty($token)) {
                Yii::$app->session->setFlash(
                    'danger',
                    \Yii::t('rabint', 'کد فعال سازی شما معتبر نیست و یا منقضی شده است!')
                );
            } else {
                $token->expire_at = time() + (60 * 5);
                $token->type = UserToken::TYPE_PASSWORD_RESET;
                $token->token = Yii::$app->security->generateRandomString(40);
                $token->save();
                Yii::$app->session->setFlash('success', \Yii::t('rabint', 'کد فعال سازی تایید شد!'));
                return $this->redirect(['reset-password', 'token' => $token->token, 'redirect' => $redirect]);
            }
        }
        $model->redirect = (empty($redirect)) ? \rabint\helpers\uri::referrer() : $redirect;
        return $this->render('getResetToken', [
            'model' => $model,
            'cell' => $cell
        ]);
    }

    /**
     * @param $token
     * @return string|Response
     * @throws BadRequestHttpException
     */
    public function actionGetActiveToken($cell='', $redirect = null)
    {
        $model = new GetResetToken();

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {

            if (!empty($model->redirect)) {
                $redirect = $model->redirect;
            }

            $token = $model->checkActiveToken();

            if (empty($token)) {
                Yii::$app->session->setFlash(
                    'danger',
                    \Yii::t('rabint', 'کد فعال سازی شما معتبر نیست و یا منقضی شده است!')
                );
            } else {
                $token->expire_at = time() + (60 * 5);
                $token->type = UserToken::TYPE_ACTIVATION;
                $token->token = Yii::$app->security->generateRandomString(40);
                $token->save();

                Yii::$app->session->setFlash('success', \Yii::t('rabint', 'کد فعال سازی تایید شد. !'));
                //send welcome message
                $text = Yii::t('rabint', 'کاربر گرامی ثبت نام شما با موفقیت انجام گردید ');
                $has_sent = Yii::$app->notify->send($token->user_id, $text, '', [
                    'priority' => Notification::MEDIA_EMAIL_AND_SMS,
                ]);
                return $this->redirect(['activation', 'token' => $token->token, 'redirect' => $redirect]);
            }
        }
        $model->redirect = (empty($redirect)) ? \rabint\helpers\uri::referrer() : $redirect;
        return $this->render('getResetToken', [
            'model' => $model,
            'cell' => $cell,
        ]);
    }

    /**
     * @param $token
     * @return string|Response
     * @throws BadRequestHttpException
     */
    public function actionResetPassword($token, $redirect = null)
    {
        try {
            $model = new ResetPasswordForm($token);
        } catch (InvalidParamException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate() && $model->resetPassword(true)) {
            if (!empty($model->redirect)) {
                $redirect = $model->redirect;
            }
            Yii::$app->session->setFlash('success', \Yii::t('rabint', 'New password was saved.'));
            //return (RabintUser::isGuest()) ? $this->redirect(['login']) : $this->redirect(['/']);
            return $this->doRedirect($redirect);
        }
        $model->redirect = (empty($redirect)) ? \rabint\helpers\uri::referrer() : $redirect;
        return $this->render('resetPassword', [
            'model' => $model,
        ]);
    }

    public function actionChangePassword($redirect = null, $cancel = 0)
    {
        if ($cancel == 1) {
            return $this->doRedirect($redirect);
        }
        $model = new \rabint\user\models\ChangePasswordForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate() && $model->changePassword()) {
            if (!empty($model->redirect)) {
                $redirect = $model->redirect;
            }
            Yii::$app->session->setFlash('success', \Yii::t('rabint', 'New password was saved.'));
            return $this->doRedirect($redirect);
        }
        $model->redirect = (empty($redirect)) ? \rabint\helpers\uri::referrer() : $redirect;
        return $this->render('changePassword', [
            'model' => $model,
            'redirect' => $redirect,
        ]);
    }

    /**
     * @param $client \yii\authclient\BaseClient
     * @return bool
     * @throws Exception
     */
    public function successOAuthCallback($client)
    {
        // use BaseClient::normalizeUserAttributeMap to provide consistency for user attribute`s names
        $attributes = $client->getUserAttributes();
        $user = User::find()->where([
            'oauth_client' => $client->getName(),
            'oauth_client_user_id' => ArrayHelper::getValue($attributes, 'id')
        ])
            ->one();
        if (!$user) {
            $user = new User();
            $user->scenario = 'oauth_create';
            $user->username = ArrayHelper::getValue($attributes, 'login');
            $user->email = ArrayHelper::getValue($attributes, 'email');
            $user->oauth_client = $client->getName();
            $user->oauth_client_user_id = ArrayHelper::getValue($attributes, 'id');
            $password = Yii::$app->security->generateRandomString(8);
            $user->setPassword($password);
            if ($user->save()) {
                $profileData = [];
                if ($client->getName() === 'facebook') {
                    $profileData['firstname'] = ArrayHelper::getValue($attributes, 'first_name');
                    $profileData['lastname'] = ArrayHelper::getValue($attributes, 'last_name');
                }
                $user->afterSignup($profileData);
                $sentSuccess = Yii::$app->commandBus->handle(new SendEmailCommand([
                    'view' => 'oauth_welcome',
                    'params' => ['user' => $user, 'password' => $password],
                    'subject' => Yii::t(
                        'rabint',
                        '{app-name} | Your login information',
                        ['app-name' => Yii::$app->name]
                    ),
                    'to' => $user->email
                ]));
                if ($sentSuccess) {
                    Yii::$app->session->setFlash(
                        'alert',
                        [
                            'options' => ['class' => 'alert-success'],
                            'body' => Yii::t(
                                'rabint',
                                'Welcome to {app-name}. Email with your login information was sent to your email.',
                                [
                                    'app-name' => Yii::$app->name
                                ]
                            )
                        ]
                    );
                }
            } else {
                // We already have a user with this email. Do what you want in such case
                if ($user->email && User::find()->where(['email' => $user->email])->count()) {
                    Yii::$app->session->setFlash(
                        'alert',
                        [
                            'options' => ['class' => 'alert-danger'],
                            'body' => Yii::t('rabint', 'We already have a user with email {email}', [
                                'email' => $user->email
                            ])
                        ]
                    );
                } else {
                    Yii::$app->session->setFlash(
                        'alert',
                        [
                            'options' => ['class' => 'alert-danger'],
                            'body' => Yii::t('rabint', 'Error while oauth process.')
                        ]
                    );
                }
            };
        }
        if (Yii::$app->user->login($user, 3600 * 24 * 30)) {
            return true;
        } else {
            throw new Exception('OAuth error');
        }
    }
    
     public function actionInvite($redirect = null)
    {
         
//         $query = \rabint\user\models\User::find();
//        $query->orWhere(['username' => str::formatCellphone('09153256682')]); #TODO
//        $query->andWhere(['not', ['id' => Yii::$app->user->getId()]]);
//        $query->andWhere(['not', ['status'=>User::STATUS_INVITED]]);
//        pr($query->createCommand()->rawSql,1);
        
        $model = new InviteForm();
        if ($model->load(Yii::$app->request->post())) {
            if (!empty($model->redirect)) {
                $redirect = $model->redirect;
            }
            $user = $model->signup();//change passs 
            if ($user) {
                if ($model->shouldBeActivated()) {

                    if (\rabint\user\Module::$cellBaseAuth) {
                        Yii::$app->session->setFlash('success', \Yii::t(
                            'rabint',
                            'لینک فعال سازی برای شما پیامک شد.'
                        ));
                        return $this->redirect(['get-active-token', 'cell' => $user->username, 'redirect' => $redirect]);
                    } else {
                        Yii::$app->session->setFlash('success', \Yii::t(
                            'rabint',
                            'لینک فعال سازی به ایمیل شما ارسال شد. لطفا پوشه inbox و یا spam ایمیل خود را بررسی نمایید'
                        ));
                        return $this->doRedirect($redirect);
                    }
                } else {
                    
                    
                    ///invite to active 
                    //
                    //send welcome message
                    $text = Yii::t('rabint', 'کاربر گرامی ثبت نام شما با موفقیت انجام گردید ');
                    $has_sent = Yii::$app->notify->send($user->id, $text, '', [
                        'priority' => Notification::MEDIA_EMAIL_AND_SMS,
                    ]);
                    Yii::$app->session->setFlash(
                        'success',
                        \Yii::t('rabint', 'کاربر گرامی!‌ ثبت نام شما انجام شد. لطفا با رجوع به پروفایل ، اطلاعات شخصی خود را کامل نمایید')
                    );
                    Yii::$app->getUser()->login($user);
                    return $this->doRedirect($redirect);
                }
                return $this->doRedirect($redirect);
            }else{
                Yii::$app->session->setFlash(
                    'danger',
                    \Yii::t('rabint', 'کاربر گرامی!‌ حساب کاربری شما در حالت دعوت نمی باشد و یا امکان ثبت دعوت از شما وجود ندارد، لطفا از بخش ثبت نام یا بازیابی رمز اقدام کنید.')
                );
            }
        }
        $model->redirect = (empty($redirect)) ? \rabint\helpers\uri::referrer() : $redirect;
        return $this->render('invite-fast', [
            'model' => $model
        ]);
    }

}