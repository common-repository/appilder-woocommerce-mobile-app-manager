<?php
if(!class_exists('WOOAPP_API_GCM_pushNotification'))
    include_once('class.pushNotification.gcm.php');
if(!class_exists('WOOAPP_API_APNS_pushNotification'))
    include_once('class.pushNotification.apns.php');

class WOOAPP_API_Core_pushNotification {
    static $gcm = 1;
    static $apns = 2;
    static $services ;
    static $table,$history_table;

    public function __construct(){
        $this->init();
    }

    static function init(){
        global $wpdb;
        self::$table = $wpdb->prefix.'wooapp_push_notification_users';
        self::$history_table = $wpdb->prefix.'wooapp_push_notification_history';
        self::$services =array(
           self::$gcm => "WOOAPP_API_GCM_pushNotification",
           self::$apns => "WOOAPP_API_APNS_pushNotification"
        );
        add_action("wooapp_activate",array('WOOAPP_API_Core_pushNotification','createTable'));
        add_action("wooapp_uninstall",array('WOOAPP_API_Core_pushNotification','dropTable'));
        add_action( 'wp_ajax_send_push_notification_to_app',array("WOOAPP_API_Core_pushNotification",'send_push_notification_to_app'));
        add_action( 'wp_ajax_action_notification_history_delete',array("WOOAPP_API_Core_pushNotification",'action_notification_history_delete'));
        add_action( 'wp_ajax_action_getHistory',array("WOOAPP_API_Core_pushNotification",'action_getHistory'));
    }

    /**
     * @todo Appending response 0 to handle (Now handled temporally by exit;
     */
    static function send_push_notification_to_app(){
       // $media_id = isset($_POST['media_id'])?$_POST['media_id']:null;
        $title = isset($_POST['title'])?$_POST['title']:null;
        $message = isset($_POST["message"])?$_POST['message']:null;
        $click_action = isset($_POST['click_action'])?$_POST['click_action']:null;
        $acton_value = isset($_POST["acton_value"])?$_POST["acton_value"]:null;
        if(!empty($title) && !empty($message) && !empty($click_action) && !empty($acton_value)){
                $push = new WOOAPP_API_Core_pushNotification();
                $response = $push->sendPush($message,$title,$click_action,$acton_value);
                echo json_encode($response);
        }
        exit;
    }

    static function action_getHistory($offset=0,$limit=10){
        global $wpdb;
        if(isset($_POST['offset']) && !empty($_POST['offset']) && is_numeric($_POST['offset']))
            $offset = (string)$_POST['offset'];
        else{
            $offset=0;
        }
        $sql = "SELECT COUNT(*) as items FROM ".self::$history_table."";
        $res = $wpdb->get_results($sql);
        $total = $res[0]->items;
        $nextOffset = false;
        $prevOffset = false;
        if($offset != 0)
            $prevOffset = $offset - $limit;
        if($total > ($offset+$limit))
            $nextOffset = $offset + $limit;
        $sql = "SELECT * FROM ".self::$history_table." ORDER BY id DESC LIMIT {$offset},{$limit}";
        $res = $wpdb->get_results($sql);
        echo "<div class='PushNotiHis'>";
        echo "Total ".$total." messages";
        foreach($res as $history){
            $data = unserialize($history->notification_data);
            $status =  unserialize($history->status);
            $time =$history->send_at;
            echo "<div id='noti_his_{$history->id}' style='border: 1px solid #ccc;padding: 3px;'><h3>{$data['title']} ({$data['actionType']}) <span style='font-size: small;font-weight: normal;float: right;'>{$time}</span></h3>
                   {$data['message']}
                    <div style='margin-top: 10px;border-top: 1px solid #ccc;'>Send to <b>{$status['success']}</b> user(s)<span style='float: right;color: red;'>
                    <a href='#' data-id='{$history->id}' class='deleteNotiItem'>Delete</a></span></div></div>"."<br />";
        }
        echo "<div>";
        if($prevOffset !==false)
            echo "<span style='float: left;'><a href='#' data-offset='$prevOffset' class='loadPushNotiHis'>&lt; Newer</a></span>";
        if($nextOffset !==false)
            echo "<span style='float: right;'><a class='loadPushNotiHis' data-offset='$nextOffset' href='#'>Older &gt;</a></span></div>";
        echo "</div>";
        if(isset($_POST['offset']))
            exit;
    }

    static function action_notification_history_delete(){
        global $wpdb;
        if(isset($_POST['id']) && !empty($_POST['id']) && is_numeric($_POST['id'])) {
            $id =$_POST['id'];
            $sql = "DELETE FROM " . self::$history_table . " WHERE `id`='$id' LIMIT 1";
            $wpdb->query($sql);
            echo "1";
        }else
            echo "0";
        exit;
    }

    static function createTable(){
        global $wpdb;
        if($wpdb->get_var("show tables like '".self::$table."'") != self::$table) {
             $sql = "CREATE TABLE " . self::$table . " (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `reg_id` text,
                        `user_id` bigint DEFAULT NULL,
                        `type` int(2) DEFAULT 1,
                        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                         PRIMARY KEY (`id`)
                    );";
             require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
             dbDelta($sql);
        }
        if($wpdb->get_var("show tables like '".self::$history_table."'") != self::$history_table) {
             $sql = "CREATE TABLE " . self::$history_table . " (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `send_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `notification_data` TEXT,
                        `status` TEXT,
                        `response` LONGTEXT,
                         PRIMARY KEY (`id`)
                    );";
             require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
             dbDelta($sql);
        }
    }

    static function dropTable(){
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {".self::$table."}");
        $wpdb->query("DROP TABLE IF EXISTS {".self::$history_table."}");
        return true;
    }




    /**
     * @param $regID
     * @param $service
     * @return bool
     */
    public function register($regID,$service){
            global $wpdb;
            $time = date("Y-m-d H:i:s");
            if (!$this->idExists($regID,$service)) {
                $sql = "INSERT INTO ".self::$table." (`reg_id`,`type`,`created_at`) VALUES ('$regID',$service,'$time')";
                $wpdb->query($sql);
                return true;
            } else {
                return false;
            }
   }
    public function addToHistory($status,$data,$response){
        global $wpdb;
        $time = date("Y-m-d H:i:s");
        $sql = "INSERT INTO ".self::$history_table." (`send_at`,`status`,`notification_data`,`response`) VALUES ('$time','$status','$data','$response')";
        $wpdb->query($sql);
        return true;
    }

    public function remove($regID,$service){
            global $wpdb;
            if ($this->idExists($regID,$service)) {
                $sql = "DELETE FROM ".self::$table." WHERE `reg_id`='{$regID}' AND `type`={$service} LIMIT 1";
                $wpdb->query($sql);
                return true;
            } else {
                return false;
            }
   }
    public function idExists($regID,$service){
        global $wpdb;
        $sql = "SELECT reg_id FROM ".self::$table." WHERE `reg_id`='{$regID}' and `type`={$service}";
        $result = $wpdb->get_results($sql);
        return (!$result)?false:true;
    }


    public function sendPush($message,$title,$actionType,$actionParam){
        $suc=0;
        $fail=0;
        $data =array();
        foreach(self::$services as $service_key => $service){
            if(self::getCount($service_key) > 0){
                /** @var WOOAPP_API_APNS_pushNotification $service_class */
                $service_class = new $service();
                $return = $service_class->send(self::getIDS($service_key),$message,$title,$actionType,$actionParam);
                $suc += $return['response']['success'];
                $fail +=  $return['response']['fail'];
                $data[$service]= $return['data'];
            }
        }
        $notification_data = array("message"=>$message,"title"=>$title,"actionType"=>$actionType,"actionParam"=>$actionParam);
        $return = array("status"=>1,"success"=>$suc,"fail"=>$fail);
        $this->addToHistory(serialize($return),serialize($notification_data),serialize($data));
        return $return;
    }


    public static function getIDS($type = 1){
        global $wpdb;
        $devices = array();
        $sql = "SELECT reg_id FROM ".self::$table." WHERE `type`=".$type."";
        $res = $wpdb->get_results($sql);
        if ($res != false) {
            foreach($res as $row){
                array_push($devices, $row->reg_id);
            }
        }
        return $devices;
    }
    public static function getCount($type = 1){
        global $wpdb;
        $sql = "SELECT COUNT(*) as items FROM ".self::$table." WHERE `type`=".$type."";
        $res = $wpdb->get_results($sql);
        if(isset($res[0]))
            $total = $res[0]->items;
        else
            $total = 0;
        return $total;
    }
}

WOOAPP_API_Core_pushNotification::init();