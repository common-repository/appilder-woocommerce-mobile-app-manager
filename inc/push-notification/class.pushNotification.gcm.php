<?php
class WOOAPP_API_GCM_pushNotification {
    var $gcm_auth_key;

    public function __construct(){
        global $mobappSettings;
        $this->gcm_auth_key = $mobappSettings['gcm_auth_key'];
    }

    public function send($ids,$message,$title,$actionType,$actionParam){
        $ids = array_chunk($ids,999);
        $url = 'https://android.googleapis.com/gcm/send';
        $headers = array(
            'Authorization: key=' . $this->gcm_auth_key,
            'Content-Type: application/json');
        $fields = array(
            'registration_ids' => array(),
            'data' => array(
                "message" => $message,
                "text" => $message,
                "title"=>$title,
                "content"=>$message,
                "actionType"=>$actionType,
                "actionParam"=>$actionParam,
                "extra"=>array(
                    "actionType"=>$actionType,
                    "actionParam"=>$actionParam
                ),
            ),
            "notification"=>array(
                "title"=>$title,
                "body"=>$message,
                "sound"=>"default",
            ),
            "content_available" => true
        );
        $suc=0;
        $fail=0;
        $answer = array();
        foreach($ids as $i=>$chunk) {
            $fields["registration_ids"] = $chunk;
            $result =$this->sendRequest($url, $fields, $headers);
            $answer[$i] = json_decode($result);
            if($answer[$i]) {
                $suc += $answer[$i]->{'success'};
                $fail += $answer[$i]->{'failure'};
            }
        }
        return array("data"=>$answer,"response"=>array("status"=>1,"success"=>$suc,"fail"=>$fail));
    }
    private function sendRequest($url,$fields,$headers){
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode( $fields ));
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
}