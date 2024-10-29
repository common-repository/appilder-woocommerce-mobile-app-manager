<?php
if(!class_exists('ApnsPHP_Push')) {
    require_once 'ApnsPHP/Autoload.php';
}
class WOOAPP_API_APNS_pushNotification {
    var $apns_production;
    var $apns_dev_cert;
    var $apns_dev_passphrase;
    var $apns_production_cert;
    var $apns_production_passphrase;


    public function __construct(){
        global $mobappSettings;
        $this->apns_production = $mobappSettings['apns_production'];
        $this->apns_dev_cert = $mobappSettings['apns_dev_cert'];
        $this->apns_production_cert = $mobappSettings['apns_production_cert'];
        //        $this->apns_production_passphrase = $mobappSettings['apns_production_passphrase'];
        //        $this->apns_dev_passphrase = $mobappSettings['apns_dev_passphrase'];
    }
    public function send($ids,$message,$title,$actionType,$actionParam){
        $upload_dir = wp_upload_dir();
        $upload_dir = $upload_dir['basedir'];
        $cert = '';
        $env = ApnsPHP_Abstract::ENVIRONMENT_PRODUCTION;
        if($this->apns_production && isset($this->apns_production_cert['url']) && !empty($this->apns_production_cert['url'])){
            $cert = preg_replace("/(.*?)uploads(.*?)/i",$upload_dir,$this->apns_production_cert['url']);
        }elseif(isset($this->apns_dev_cert['url']) && !empty($this->apns_dev_cert['url'])){
            $cert = preg_replace("/(.*?)uploads(.*?)/i",$upload_dir,$this->apns_dev_cert['url']);
            $env = ApnsPHP_Abstract::ENVIRONMENT_SANDBOX;
        }

        if(empty($cert) || !file_exists($cert)){
            return array("data"=>array(),"response"=>array("status"=>1,"success"=>0,"fail"=>count($ids)));
        }

        $push = new ApnsPHP_Push($env, $cert);
        $push->setLogger(new ApnsPHP_Appilder_Logger_Embedded());

        // Instanciate a new ApnsPHP_Push object
        //  $push->setRootCertificationAuthority('entrust_root_certification_authority.pem');
        // $push->setWriteInterval(100 * 1000);

        $push->connect();
        $suc=0;
        $fail=0;
        foreach($ids as $key=>$id) {
            try {
                if (preg_match('~^[a-f0-9]{64}$~i', $id)) {
                    // Instantiate a new Message with a single recipient
                    $msg = new ApnsPHP_Message_Custom($id);
                    // Set a custom identifier. To get back this identifier use the getCustomIdentifier() method
                    // over a ApnsPHP_Message object retrieved with the getErrors() message.
                    $msg->setCustomIdentifier(sprintf("Message-Badge-%03d", $key));
                    $msg->setCustomProperty("actionType", $actionType);
                    $msg->setCustomProperty("actionParam", $actionParam);
                    $msg->setText($message);
                    $msg->setTitle($title);
                    //$message->setBadge($i);
                    // Add the message to the message queue
                    $push->add($msg);
                    $suc++;
                }else{
                    $fail++;
                }

            } catch(ApnsPHP_Push_Exception $e){
                $fail++;
            }
        }
        // Send all messages in the message queue
        if($suc > 0)
            $push->send();
        // Disconnect from the Apple Push Notification Service
        $push->disconnect();

        // Examine the error message container
        $aErrorQueue = $push->getErrors();
        if (!empty($aErrorQueue)) {
            $suc -= count($aErrorQueue);
            $fail += count($aErrorQueue);
        }
        return array("data"=>array(),"response"=>array("status"=>1,"success"=>$suc,"fail"=>$fail));
    }

}
class ApnsPHP_Appilder_Logger_Embedded implements ApnsPHP_Log_Interface
{
    /**
     * Logs a message.
     *
     * @param  $sMessage @type string The message.
     */
    public function log($sMessage)
    {
        //  printf("%s ApnsPHP[%d]: %s\n",
        //      date('r'), getmypid(), trim($sMessage)
        //  );
    }
}