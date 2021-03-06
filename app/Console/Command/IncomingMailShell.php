<?php

App::uses('Travel', 'Model');
App::uses('CakeEmail', 'Network/Email');

App::uses('ComponentCollection', 'Controller');
App::uses('Controller', 'Controller');
App::uses('TravelLogicComponent', 'Controller/Component');

require_once("PlancakeEmailParser.php");

class IncomingMailShell extends AppShell {   
    
    private $TravelLogic;
    
    private static $MAX_MATCHING_OFFSET = 0.3;
    
    public $uses = array('Locality', 'DriverLocality', 'TravelByEmail', 'User', 'LocalityThesaurus');

    public function main() {
        $this->out('IncomingMail shell reporting.');
    }

    public function process() {
        $sender = $this->args[0];
        $origin = $this->args[1];
        $destination = $this->args[2];
        $description = $this->args[3];
        
        $this->do_process($sender, $origin, $destination, $description);
    }
    
    public function process2() {        
        $stdin = fopen('php://stdin', 'r');
        $emailParser = new PlancakeEmailParser(stream_get_contents($stdin));
        fclose($stdin);
        
        $text = $emailParser->getHeader('From');
        preg_match('#\<(.*?)\>#', $text, $match);
        $sender = $match[1];
        if($sender == null || strlen($sender) == 0) $sender = $text;
        
        $target = $emailParser->getTo();        
        $to = $target[0];
        $to = str_replace('<', '', $to);
        $to = str_replace('>', '', $to);
        
        $subject = trim($emailParser->getSubject());
        
        $body = h($emailParser->getPlainBody()); // h() para escapar los caracteres html
        
        if($to === 'viajes@yotellevo.ahiteva.net') {
            CakeLog::write('travels_by_email', 'Travel Created - Sender: '.$sender.' | Subject: '.$subject.' | Body: '.$body);
            
            $subject = str_replace("'", "", $subject);
            $subject = str_replace('"', "", $subject);

            // TODO: Verificar que origen y destino se pudieron sacar del asunto
            $parseOK = preg_match('/(?<from>.+)-(?<to>.+)/', $subject, $matches);
            if($parseOK) {
                $origin = trim($matches['from']);
                $destination = trim($matches['to']);                
                
                preg_match_all('/(?<!\w)#\w+/', $body, $hashtags);

                $this->do_process($sender, $origin, $destination, $body, $hashtags[0]);
            } else {
                $this->out('Fail');
                CakeLog::write('travels_by_email', 'Error: Wrong Subject');
                if(Configure::read('enqueue_mail')) {
                    ClassRegistry::init('EmailQueue.EmailQueue')->enqueue(
                            $sender,
                            array('subject' => $subject), 
                            array(
                                'template'=>'travel_by_email_wrong_subject',
                                'format'=>'html',
                                'subject'=>'Anuncio de Viaje Fallido (Origen-Destino incorrectos)',
                                'config'=>'no_responder')
                            );
                } else {
                    $Email = new CakeEmail('no_responder');
                    $Email->template('travel_by_email_wrong_subject')
                    ->viewVars(array('subject' => $subject))
                    ->emailFormat('html')
                    ->to($sender)
                    ->subject('Anuncio de Viaje Fallido (Origen-Destino incorrectos)');
                    try {
                        $Email->send();
                    } catch ( Exception $e ) {
                        // TODO: What to do here?
                    }
                } 
            }
            
            CakeLog::write('travels_by_email', '----------------------------------------------------------------------------');
            
        } else if($to === 'info@yotellevo.ahiteva.net') {
            CakeLog::write('info_requested', 'Info Requested - Sender: '.$sender.' | Subject: '.$subject.' | Body: '.$body);
            
            if(Configure::read('enqueue_mail')) {
                ClassRegistry::init('EmailQueue.EmailQueue')->enqueue(
                        $sender,
                        array(), 
                        array(
                            'template'=>'info',
                            'format'=>'html',
                            'subject'=>'Sobre YoTeLlevo',
                            'config'=>'no_responder')
                        );
            } else {
                $Email = new CakeEmail('no_responder');
                $Email->template('info')
                ->viewVars(array())
                ->emailFormat('html')
                ->to($sender)
                ->subject('Sobre YoTeLlevo');
                try {
                    $Email->send();
                } catch ( Exception $e ) {
                    // TODO: What to do here?
                }
            }
        }    
    }
    
    private function do_process($sender, $origin, $destination, $description, $hashtags = array()) {
        $shortest = -1;
        $closest = array();
        $perfectMatch = false;
        
        $localities = $this->Locality->getAsList();
        foreach ($localities as $province => $municipalities) {            
            foreach ($municipalities as $munId=>$munName) {
                
                $result = $this->match($origin, $destination, $munName, $shortest);
                if($result != null && !empty ($result)) {
                    $closest = $result + array('locality_id'=>$munId);                    
                    $shortest = $closest['distance'];
                    
                    //$this->out($munName.':'.$shortest);
                    
                    if($shortest == 0) {
                        $perfectMatch = true;
                        break;
                    }
                }
            }
            
            if($perfectMatch) break;
        }
        
        if(!$perfectMatch) { // Si no hay match perfecto, ver si hay un mejor matcheo con el tesauro
            $thesaurus = $this->LocalityThesaurus->find('all');
            foreach ($thesaurus as $t) {
                
                $target = $t['LocalityThesaurus']['fake_name'];
                $split = explode('|', $target);
                $target = $split[0];
                //$this->out($t['LocalityThesaurus']['fake_name']);
                
                
                $result = $this->match($origin, $destination, $target, $shortest);
                if($result != null && !empty ($result)) {
                    $closest = $result + array('locality_id'=>$t['LocalityThesaurus']['locality_id']);
                    $shortest = $closest['distance'];
                    
                    if($shortest == 0) {
                        $perfectMatch = true;
                        break;
                    }
                }
            }
        }
        
        $datasource = $this->TravelByEmail->getDataSource();
        $datasource->begin();
        $OK = true;
        
        $user = $this->User->findByUsername($sender);
            
        if($user == null || empty ($user)) {
            $user = array('User');
            $user['User']['username'] = $sender;
            $user['User']['password'] = 'email123';// TODO
            $user['User']['role'] = 'regular';
            $user['User']['active'] = true;
            $user['User']['email_confirmed'] = true;
            $user['User']['register_type'] = 'email';
            if($this->User->save($user)) {
                $userId = $this->User->getLastInsertID();
            } else {
                $OK = false;
            }

        } else {
            $userId = $user['User']['id'];
            
            if(!$user['User']['email_confirmed']) {
                
                $user['User']['email_confirmed'] = true;
                $this->User->id = $userId;
                $OK = $this->User->saveField('email_confirmed', '1');
            }
        }
        
        $result = array();        
        if($OK && $closest != null && !empty ($closest)) {
            $this->out(print_r($closest, true));
            
            $travel = array('TravelByEmail');
            $travel['TravelByEmail']['user_origin'] = $origin;
            $travel['TravelByEmail']['user_destination'] = $destination;
            $travel['TravelByEmail']['description'] = $description;
            $travel['TravelByEmail']['matched'] = $closest['name'];
            $travel['TravelByEmail']['locality_id'] = $closest['locality_id'];
            $travel['TravelByEmail']['where'] = $closest['direction'] == 0? $destination : $origin;
            $travel['TravelByEmail']['direction'] = $closest['direction'];
            $travel['TravelByEmail']['user_id'] = $userId;
            $travel['TravelByEmail']['state'] = Travel::$STATE_CONFIRMED;
            $travel['User'] = $user['User'];
            
            
            //print_r($hashtags);
            $travel['TravelByEmail']['need_modern_car'] = false;
            $travel['TravelByEmail']['need_air_conditioner'] = false;
            if($hashtags != null && !empty ($hashtags)) {
                foreach ($hashtags as $tag) {
                    echo strtolower($tag);
                    if(strtolower($tag) === '#moderno') $travel['TravelByEmail']['need_modern_car'] = true;
                    else if(strtolower($tag) === '#aire') $travel['TravelByEmail']['need_air_conditioner'] = true;
                }
            }
            
            
            $this->TravelLogic =& new TravelLogicComponent(new ComponentCollection());
            $result = $this->TravelLogic->confirmTravel('TravelByEmail', $travel);
            
            $OK = $result['success'];
            $this->out($result['message']);
            
        } else {
            $result['message'] = 'Origin and Destination not recognized';
            $OK = false;
        }
        
        $travelText = '('.$origin.' - '.$destination.' : '.$sender.')';
        
        if($OK) {
            $datasource->commit();
            CakeLog::write('travels_by_email', $travelText.' Mejor coincidencia: '.  $closest['name'].' -> '.(1.0 - $shortest/strlen($closest['name'])).' [ACEPTADO]');
            
            if(Configure::read('enqueue_mail')) {
                ClassRegistry::init('EmailQueue.EmailQueue')->enqueue(
                        $sender,
                        array('travel' => $travel), 
                        array(
                            'template'=>'travel_by_email_match',
                            'format'=>'html',
                            'subject'=>'Creado Anuncio de Viaje ('.$origin.'-'.$destination.')',
                            'config'=>'no_responder')
                        );
                
                //$this->out('email enqueued');
            } else {
                $Email = new CakeEmail('no_responder');
                $Email->template('travel_by_email_match')
                ->viewVars(array('travel' => $travel))
                ->emailFormat('html')
                ->to($sender)
                ->subject('Creado Anuncio de Viaje ('.$origin.'-'.$destination.')');
                try {
                    $Email->send();
                } catch ( Exception $e ) {
                    // TODO: What to do here?
                }
            }
        } else {
            $datasource->rollback();
            CakeLog::write('travels_by_email', $travelText.' [NO ACEPTADO: '.$result['message'].']');
            
            $this->out('Fail');
            if(Configure::read('enqueue_mail')) {
                ClassRegistry::init('EmailQueue.EmailQueue')->enqueue(
                        $sender,
                        array(
                            'user_origin' => $origin, 
                            'user_destination' => $destination,
                            'localities' =>$localities,
                            'thesaurus' => $thesaurus
                            ), 
                        array(
                            'template'=>'travel_by_email_no_match',
                            'format'=>'html',
                            'subject'=>'Anuncio de Viaje Fallido ('.$origin.'-'.$destination.')',
                            'config'=>'no_responder')
                        );
                
                //$this->out('email enqueued');
            } else {
                $Email = new CakeEmail('no_responder');
                $Email->template('travel_by_email_no_match')
                ->viewVars(array(
                    'user_origin' => $origin, 
                    'user_destination' => $destination,
                    'localities' =>$localities,
                    'thesaurus' => $thesaurus
                ))
                ->emailFormat('html')
                ->to($sender)
                ->subject('Anuncio de Viaje Fallido');
                try {
                    $Email->send();
                } catch ( Exception $e ) {
                    // TODO: What to do here?
                }
            } 
        }
    }
    
    private function match($origin, $destination, $target, $shortestSoFar) {
        $closest = null;
        
        $levOrigin = levenshtein(strtoupper($target), strtoupper($origin));
        $levDestination = levenshtein(strtoupper($target), strtoupper($destination));

        $percentOrigin = $levOrigin/strlen($target);
        $percentDestination = $levDestination/strlen($target);
        
        //$this->out($origin.'|'.$target.': '.$percentOrigin);
        //$this->out($destination.'|'.$target.': '.$percentDestination);

        // Calculate only if inside offset
        if($percentOrigin > IncomingMailShell::$MAX_MATCHING_OFFSET && 
           $percentDestination > IncomingMailShell::$MAX_MATCHING_OFFSET) return null;
            
        // Check for an exact match
        if ($levOrigin == 0 || $levDestination == 0) {
            $direction = $levOrigin == 0? 0 : 1;

            // Closest locality (exact match)
            $shortestSoFar = 0;
            $closest = array('name'=>$target, 'direction'=>$direction, 'distance'=>$shortestSoFar);                
            return $closest;
        }

        if ($levOrigin < $shortestSoFar || $shortestSoFar < 0) {
            // set the closest match, and shortest distance
            $shortestSoFar = $levOrigin;
            $closest = array('name'=>$target, 'direction'=>0, 'distance'=>$shortestSoFar);                
        }
        if ($levDestination < $shortestSoFar || $shortestSoFar < 0) {
            // set the closest match, and shortest distance
            $shortestSoFar = $levDestination;
            $closest = array('name'=>$target, 'direction'=>1, 'distance'=>$shortestSoFar);                
        } 
        
        return $closest;
        
    }   
    
}

?>
