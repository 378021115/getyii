<?php
namespace common\models;

use Yii;
use yii\base\NotSupportedException;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use common\models\UserInfo;
use yii\web\IdentityInterface;
use yii\helpers\Json;
use common\components\db\Mailer;
use yii\log\Logger;

/**
 * User model
 *
 * @property integer $id
 * @property string $username
 * @property string $avatar
 * @property string $password_hash
 * @property string $password_reset_token
 * @property string $email
 * @property string $auth_key
 * @property integer $role
 * @property integer $status
 * @property integer $created_at
 * @property integer $updated_at
 * @property string $tagline
 * @property string $password write-only password
 */
class User extends ActiveRecord implements IdentityInterface
{
    const STATUS_DELETED = 0;
    const STATUS_ACTIVE = 10;
    const ROLE_USER = 10;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%user}}';
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            TimestampBehavior::className(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            ['status', 'default', 'value' => self::STATUS_ACTIVE],
            ['status', 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_DELETED]],

            ['role', 'default', 'value' => self::ROLE_USER],
            ['role', 'in', 'range' => [self::ROLE_USER]],
        ];
    }

    /**
     * @inheritdoc
     */
    public static function findIdentity($id)
    {
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    /**
     * @inheritdoc
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        throw new NotSupportedException('"findIdentityByAccessToken" is not implemented.');
    }

    /**
     * Finds user by username
     *
     * @param string $username
     * @return static|null
     */
    public static function findByUsername($username)
    {
        return static::findOne(['username' => $username, 'status' => self::STATUS_ACTIVE]);
    }

    /**
     * Finds user by password reset token
     *
     * @param string $token password reset token
     * @return static|null
     */
    public static function findByPasswordResetToken($token)
    {
        if (!static::isPasswordResetTokenValid($token)) {
            return null;
        }

        return static::findOne([
            'password_reset_token' => $token,
            'status' => self::STATUS_ACTIVE,
        ]);
    }

    /**
     * Finds out if password reset token is valid
     *
     * @param string $token password reset token
     * @return boolean
     */
    public static function isPasswordResetTokenValid($token)
    {
        if (empty($token)) {
            return false;
        }
        $expire = Yii::$app->params['user.passwordResetTokenExpire'];
        $parts = explode('_', $token);
        $timestamp = (int) end($parts);
        return $timestamp + $expire >= time();
    }

    /**
     * @inheritdoc
     */
    public function getId()
    {
        return $this->getPrimaryKey();
    }

    /**
     * @inheritdoc
     */
    public function getAuthKey()
    {
        return $this->auth_key;
    }

    /**
     * @inheritdoc
     */
    public function validateAuthKey($authKey)
    {
        return $this->getAuthKey() === $authKey;
    }

    /**
     * Validates password
     *
     * @param string $password password to validate
     * @return boolean if password provided is valid for current user
     */
    public function validatePassword($password)
    {
        return Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    /**
     * Generates password hash from password and sets it to the model
     *
     * @param string $password
     */
    public function setPassword($password)
    {
        $this->password_hash = Yii::$app->security->generatePasswordHash($password);
    }

    /**
     * Generates "remember me" authentication key
     */
    public function generateAuthKey()
    {
        $this->auth_key = Yii::$app->security->generateRandomString();
    }

    /**
     * Generates new password reset token
     */
    public function generatePasswordResetToken()
    {
        $this->password_reset_token = Yii::$app->security->generateRandomString() . '_' . time();
    }

    /**
     * Removes password reset token
     */
    public function removePasswordResetToken()
    {
        $this->password_reset_token = null;
    }

     /**
     * 根据 email 获取 gravatar 头像的地址
     * @param $email
     * @param int $size
     * @return string
     */
    public function getGravatarUrl($email, $size = 64){
        $gravatar = sprintf('http://gravatar.com/avatar/%s?s=%d', md5($email), $size);
        return $gravatar;
    }

    public function getUserInfo()
    {
        return $this->hasOne(UserInfo::className(), ['user_id' => 'id']);
    }

    /**
     * @return Account[] Connected accounts ($provider => $account)
     */
    public function getAccounts()
    {
        $connected = [];
        //$accounts  = $this->hasMany($this->module->modelMap['Account'], ['user_id' => 'id'])->all();

        // @var Account $account
        // foreach ($accounts as $account) {
        //     $connected[$account->provider] = $account;
        // }

        return $connected;
    }

    public function create($accountData)
    {
        $account = Json::decode($accountData);

        if ($this->getIsNewRecord() == false) {
            throw new \RuntimeException('Calling "' . __CLASS__ . '::' . __METHOD__ . '" on existing user');
        }

        $password = Yii::$app->security->generateRandomString('8');
        $time = time();
        $this->username = $account['login'];
        $this->avatar = $account['avatar_url'];
        $this->setPassword($password);
        $this->generateAuthKey();
        $this->email = $account['email'];

        if ($this->save()) {
            // $this->mailer->sendWelcomeMessage($this);
            Yii::getLogger()->log('User has been created', Logger::LEVEL_INFO);
            return true;
        }

        Yii::getLogger()->log('An error occurred while creating user account', Logger::LEVEL_ERROR);

        return false;
    }

    /** @inheritdoc */
    public function afterSave($insert, $changedAttributes)
    {
        if ($insert) {
            $time = time();
            $userInfo = Yii::createObject([
                'class'           => UserInfo::className(),
                'user_id'         => $this->id,
                'prev_login_time' => $time,
                'prev_login_ip'   => Yii::$app->request->userIP,
                'last_login_time' => $time,
                'last_login_ip'   => Yii::$app->request->userIP,
                'created_at'      => $time,
                'updated_at'      => $time,
            ]);
            $userInfo->save();
        }
        parent::afterSave($insert, $changedAttributes);
    }
}
