<?php
App::uses('AppModel', 'Model');
class Travel extends AppModel {
    
    public $travelType = '';
    
    public static $STATE = array(
        'P' => array('color'=>'green', 'label'=>'Pendiente'),
        'U' => array('color'=>'goldenrod', 'label'=>'Pendiente de Confirmación'),
        'C' => array('color'=>'#0088cc', 'label'=>'Confirmado'),
        'E' => array('color'=>'lightcoral', 'label'=>'Expirado'),
    );
    
    public static $STATE_PENDING = 'P';
    public static $STATE_UNCONFIRMED = 'U';
    public static $STATE_CONFIRMED = 'C';
    public static $STATE_SOLVED = 'S';
    
    public static $STATE_DEFAULT = 'U';
    
    public static $preferences = array(
        'need_modern_car'=>'Carro Moderno',
        'need_air_conditioner'=>'Aire Acondicionado'
    );
    
    public $order = 'Travel.id DESC';
    
    public $belongsTo = array(
        'Locality' => array(
            'fields'=>array('id', 'name')
        ),
        'User' => array(
            'fields'=>array('id', 'username', 'role'),
            'counterCache'=>true
        )
    );

    public $validate = array(
        'where' => array(
            'notEmpty' => array(
                'rule' => array('notEmpty'),
                'message' => 'El destino no puede estar vacío.'
            )
        ),
        'date' => array(
            'isDate' => array(
                'rule' => array('date', array('dmy', 'ymd')),
                'message' => 'La fecha tiene un formato incorrecto.',
                'required' => true
            )
        ),
        'people_count' => array(
            'isNumber' => array(
                'rule' => 'numeric',
                'message' => 'La cantidad de personas debe ser un número entero.',
                'required' => true
            ),
            'notZero' => array(
                'rule' => array('comparison', '>=', 1),
                'message' => 'La cantidad de personas no puede ser 0.',
                'required' => true
            )
        ),
        'contact' => array(
            'notEmpty' => array(
                'rule' => array('notEmpty'),
                'message' => 'Debe escribir la forma de contacto.'
            )
        )
    );
    
    public function beforeSave($options = array()) {
        if (isset($this->data[$this->alias]['date'])) {
            if($this->useDbConfig == 'mysql') {
                $d = str_replace('-', '/', $this->data[$this->alias]['date']);
                $d = explode('/', $d);
                $newD = $d['2'].'-'.$d[1].'-'.$d[0];
                $this->data[$this->alias]['date'] = $newD;
            }   
        }
        return true;
    }
    
    public function afterFind($results, $primary = false) {
        foreach ($results as $key => $val) {
            if (isset($val['Travel']['date'])) {
                $results[$key]['Travel']['date'] = $this->dateFormatAfterFind($val['Travel']['date']);
            }
        }
        return $results;
    }
    
    public function afterSave($created, array $options = array()) {
        parent::afterSave($created, $options);
        
        if($created) {
            CakeSession::write('Auth.User.travel_count', CakeSession::read('Auth.User.travel_count') + 1);
        }
    }
    
    public function afterDelete() {
        parent::afterDelete();
            
        CakeSession::write('Auth.User.travel_count', CakeSession::read('Auth.User.travel_count') - 1);
    }
        
    public function dateFormatAfterFind($date) {
        return date('d-m-Y', strtotime($date));
    }

    public function isOwnedBy($id, $user_id) {
        return $this->field('id', array('id' => $id, 'user_id' => $user_id)) == $id;
    }
    
    public static function isConfirmed($travelState) {
        return $travelState === Travel::$STATE_CONFIRMED || $travelState === Travel::$STATE_SOLVED;
    }
}

?>