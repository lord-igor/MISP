<?php
App::uses('AppModel', 'Model');
App::uses('RandomTool', 'Tools');

/**
 * @property User $User
 */
class AuthKey extends AppModel
{
    public $recursive = -1;

    public $actsAs = array(
        'SysLogLogable.SysLogLogable' => array(
                'userModel' => 'User',
                'userKey' => 'user_id',
                'change' => 'full'),
        'Containable',
    );

    public $belongsTo = array(
        'User'
    );

    public $authkey_raw = false;

    // massage the data before we send it off for validation before saving anything
    public function beforeValidate($options = array())
    {
        //parent::beforeValidate();
        if (empty($this->data['AuthKey']['id'])) {
            if (empty($this->data['AuthKey']['uuid'])) {
                $this->data['AuthKey']['uuid'] = CakeText::uuid();
            }
            if (empty($this->data['AuthKey']['authkey'])) {
                $authkey = (new RandomTool())->random_str(true, 40);
            } else {
                $authkey = $this->data['AuthKey']['authkey'];
            }
            $passwordHasher = $this->getHasher();
            $this->data['AuthKey']['authkey'] = $passwordHasher->hash($authkey);
            $this->data['AuthKey']['authkey_start'] = substr($authkey, 0, 4);
            $this->data['AuthKey']['authkey_end'] = substr($authkey, -4);
            $this->data['AuthKey']['authkey_raw'] = $authkey;
            $this->authkey_raw = $authkey;

            $validity = Configure::read('Security.advanced_authkeys_validity');
            if (empty($this->data['AuthKey']['expiration'])) {
                $this->data['AuthKey']['expiration'] = $validity ? strtotime("+$validity days") : 0;
            } else {
                $expiration = is_numeric($this->data['AuthKey']['expiration']) ?
                    (int)$this->data['AuthKey']['expiration'] :
                    strtotime($this->data['AuthKey']['expiration']);

                if ($expiration === false) {
                    $this->invalidate('expiration', __('Expiration must be in YYYY-MM-DD format.'));
                }
                if ($validity && $expiration > strtotime("+$validity days")) {
                    $this->invalidate('expiration', __('Maximal key validity is %s days.', $validity));
                }
                $this->data['AuthKey']['expiration'] = $expiration;
            }
        }
        return true;
    }

    /**
     * @param string $authkey
     * @return array|false
     */
    public function getAuthUserByAuthKey($authkey)
    {
        $start = substr($authkey, 0, 4);
        $end = substr($authkey, -4);
        $existing_authkeys = $this->find('all', [
            'recursive' => -1,
            'fields' => ['id', 'authkey', 'user_id', 'expiration'],
            'conditions' => [
                'OR' => [
                    'expiration >' => time(),
                    'expiration' => 0
                ],
                'authkey_start' => $start,
                'authkey_end' => $end,
            ]
        ]);
        $passwordHasher = $this->getHasher();
        foreach ($existing_authkeys as $existing_authkey) {
            if ($passwordHasher->check($authkey, $existing_authkey['AuthKey']['authkey'])) {
                $user = $this->User->getAuthUser($existing_authkey['AuthKey']['user_id']);
                if ($user) {
                    $user['authkey_id'] = $existing_authkey['AuthKey']['id'];
                    $user['authkey_expiration'] = $existing_authkey['AuthKey']['expiration'];
                }
                return $user;
            }
        }
        return false;
    }

    public function resetauthkey($id)
    {
        $existing_authkeys = $this->find('all', [
            'recursive' => -1,
            'conditions' => [
                'user_id' => $id
            ]
        ]);
        foreach ($existing_authkeys as $key) {
            $key['AuthKey']['expiration'] = time();
            $this->save($key);
        }
        return $this->createnewkey($id);
    }

    public function createnewkey($id)
    {
        $newKey = [
            'authkey' => (new RandomTool())->random_str(true, 40),
            'user_id' => $id
        ];
        $this->create();
        if ($this->save($newKey)) {
            return $newKey['authkey'];
        } else {
            return false;
        }
    }

    /**
     * @param int $id
     * @return array
     * @throws Exception
     */
    public function getKeyUsage($id)
    {
        $redis = $this->setupRedisWithException();
        $data = $redis->hGetAll("misp:authkey_usage:$id");

        $output = [];
        $uniqueIps = [];
        foreach ($data as $key => $count) {
            list($date, $ip) = explode(':', $key);
            $uniqueIps[$ip] = true;
            if (isset($output[$date])) {
                $output[$date] += $count;
            } else {
                $output[$date] = $count;
            }
        }
        // Data from redis are not sorted
        ksort($output);

        $lastUsage = $redis->get("misp:authkey_last_usage:$id");
        $lastUsage = $lastUsage === false ? null : (int)$lastUsage;

        return [$output, $lastUsage, count($uniqueIps)];
    }

    /**
     * @param array $ids
     * @return array<DateTime|null>
     * @throws Exception
     */
    public function getLastUsageForKeys(array $ids)
    {
        $redis = $this->setupRedisWithException();
        $keys = array_map(function($id) {
            return "misp:authkey_last_usage:$id";
        }, $ids);
        $lastUsages = $redis->mget($keys);
        $output = [];
        foreach (array_values($ids) as $i => $id) {
            $output[$id] = $lastUsages[$i] === false ? null : (int)$lastUsages[$i];
        }
        return $output;
    }

    /**
     * When key is deleted, update after `date_modified` for user that was assigned to that key, so session data
     * will be realoaded and canceled.
     * @see AppController::_refreshAuth
     */
    public function afterDelete()
    {
        parent::afterDelete();
        $userId = $this->data['AuthKey']['user_id'];
        $this->User->updateAll(['date_modified' => time()], ['User.id' => $userId]);
    }

    /**
     * @return AbstractPasswordHasher
     */
    private function getHasher()
    {
        return new BlowfishPasswordHasher();
    }
}
