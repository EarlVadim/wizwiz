<?php
include_once 'config.php';
check();
$robotState = $botState['botState']??"on";
if ($userInfo['step'] == "banned" && $from_id != $admin && $userInfo['isAdmin'] != true) {
    sendMessage($mainValues['banned']);
    exit();
}
$checkSpam = checkSpam();
if(is_numeric($checkSpam)){
    $time = jdate("Y-m-d H:i:s", $checkSpam);
    sendMessage("Ваш аккаунт заблокирован из-за спамаا: \n$time");
    exit();
}
if(preg_match("/^haveJoined(.*)/",$data,$match)){
    if ($joniedState== "kicked" || $joniedState== "left"){
        alert($mainValues['not_joine_yet']);
        exit();
    }else{
        delMessage();
        $text = $match[1];
    }
}
if (($joniedState== "kicked" || $joniedState== "left") && $from_id != $admin){
    sendMessage(str_replace("CHANNEL-ID", $channelLock, $mainValues['join_channel_message']), json_encode(['inline_keyboard'=>[
        [['text'=>$buttonValues['join_channel'],'url'=>"https://t.me/" . str_replace("@", "", $botState['lockChannel'])]],
        [['text'=>$buttonValues['have_joined'],'callback_data'=>'haveJoined' . $text]],
        ]]),"HTML");
    exit;
}
if($robotState == "off" && $from_id != $admin){
    sendMessage($mainValues['bot_is_updating']);
    exit();
}
if(strpos($text, "/start ") !== false){
    $inviter = str_replace("/start ", "", $text);
    
    if($uinfo->num_rows == 0 && $inviter != $from_id){
        
        $first_name = !empty($first_name)?$first_name:" ";
        $username = !empty($username)?$username:" ";
        if($uinfo->num_rows == 0){
            $sql = "INSERT INTO `users` (`userid`, `name`, `username`, `refcode`, `wallet`, `date`, `refered_by`)
                                VALUES (?,?,?, 0,0,?,?)";
            $stmt = $connection->prepare($sql);
            $time = time();
            $stmt->bind_param("issii", $from_id, $first_name, $username, $time, $inviter);
            $stmt->execute();
            $stmt->close();
        }else{
            $refcode = time();
            $sql = "UPDATE `users` SET `refered_by` = ? WHERE `userid` = ?";
            $stmt = $connection->prepare($sql);
            $stmt->bind_param("si", $inviter, $from_id);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $uinfo = $stmt->get_result();
        $userInfo = $uinfo->fetch_assoc();
        $stmt->close();
        
        setUser("referedBy" . $inviter);
        $userInfo['step'] = "referedBy" . $inviter;
        sendMessage($mainValues['invited_user_joined_message'],null,null, $inviter);
    }
    
    $text = "/start";
}
if(($data == "agentsList" || preg_match('/^nextAgentList(\d+)/',$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getAgentsList($match[1]??0);
    if($keys != null) editText($message_id,$mainValues['agents_list'], $keys);
    else alert("Представитель не найден");
}
if(preg_match('/^agentDetails(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $userDetail = bot('getChat',['chat_id'=>$match[1]])->result;
    $userUserName = $userDetail->username;
    $fullName = $userDetail->first_name . " " . $userDetail->last_name;

    editText($message_id,str_replace("AGENT-NAME", $fullName, $mainValues['agent_details']), getAgentDetails($match[1]));
}
if(preg_match('/^removeAgent(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 0 WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert($mainValues['agent_deleted_successfuly']);
    $keys = getAgentsList();
    if($keys != null) editKeys($keys);
    else editKeys(json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]]]));
}
if(preg_match('/^agentPercentDetails(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match[1]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userName = $info['name'];
    editText($message_id, str_replace("AGENT-NAME", $userName, $mainValues['agent_discount_settings']), getAgentDiscounts($match[1]));
}
if(preg_match('/^addDiscount(Server|Plan)Agent(\d+)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match[2]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $userName = $info['name'];
    
    if($match[1] == "Plan"){
        $offset = 0;
        $limit = 20;
        
        $condition = array_values(array_keys(json_decode($info['discount_percent'],true)['plans']??array()));
        $condition = count($condition) > 0? "WHERE `id` NOT IN (" . implode(",", $condition) . ")":"";
        $stmt = $connection->prepare("SELECT * FROM `server_plans` $condition LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        if($list->num_rows > 0){
            $keys = array();
            while($row = $list->fetch_assoc()){
                $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
                $stmt->bind_param("i", $row['catid']);
                $stmt->execute();
                $catInfo = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                $keys[] = [['text'=>$row['title'] . " " . $catInfo['title'],'callback_data'=>"editAgentDiscountPlan" . $match[2] . "_" . $row['id']]];
            }
            
            if($list->num_rows >= $limit){
                $keys[] = [['text'=>"▶️",'callback_data'=>"nextAgentDiscountPlan" . $match[2] . "_" . ($offset + $limit)]];
            }
            $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentPercentDetails" . $match[2]]];
            $keys = json_encode(['inline_keyboard'=>$keys]);
            
            editText($message_id,"Пожалуйста, выберите желаемый сервер, чтобы добавить скидку агенту $userName Выбирать",$keys);
        }else alert("Серверов не осталось");
    }else{
        $condition = array_values(array_keys(json_decode($info['discount_percent'],true)['servers']??array()));
        $condition = count($condition) > 0? "WHERE `id` NOT IN (" . implode(",", $condition) . ")":"";
        $stmt = $connection->prepare("SELECT * FROM `server_info` $condition");
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        if($list->num_rows > 0){
            $keys = array();
            while($row = $list->fetch_assoc()){
                $keys[] = [['text'=>$row['title'],'callback_data'=>"editAgentDiscountServer" . $match[2] . "_" . $row['id']]];
            }
            
            $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentPercentDetails" . $match[2]]];
            $keys = json_encode(['inline_keyboard'=>$keys]);
            
            editText($message_id,"Пожалуйста, выберите желаемый сервер, чтобы добавить скидку агенту $userName Выбирать",$keys);
        }else alert("Серверов не осталось");
    }
}
if(preg_match('/^nextAgentDiscountPlan(?<agentId>\d+)_(?<offset>\d+)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match['agentId']);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $userName = $info['name'];
    
    $offset = $match['offset'];
    $limit = 20;
    
    $condition = array_values(array_keys(json_decode($info['discount_percent'],true)['plans']??array()));
    $condition = count($condition) > 0? "WHERE `id` NOT IN (" . implode(",", $condition) . ")":"";
    $stmt = $connection->prepare("SELECT * FROM `server_plans` $condition LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
    
    if($list->num_rows > 0){
        $keys = array();
        while($row = $list->fetch_assoc()){
            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
            $stmt->bind_param("i", $row['catid']);
            $stmt->execute();
            $catInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $keys[] = [['text'=>$row['title'] . " " . $catInfo['title'],'callback_data'=>"editAgentDiscountPlan" . $match['agentId'] . "_" . $row['id']]];
        }
        
        if($list->num_rows >= $limit && $offset == 0){
            $keys[] = [['text'=>"▶️",'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset + $limit)]];
        }
        elseif($list->num_rows >= $limit && $offset != 0){
            $keys[] = [
                ['text'=>"◀️️",'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset - $limit)],
                ['text'=>"▶️",'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset + $limit)]
                ];
        }
        elseif($offset != 0){
            $keys[] = [
                ['text'=>"◀️️",'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset - $limit)]
                ];
        }
        $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentPercentDetails" . $match['agentId']]];
        $keys = json_encode(['inline_keyboard'=>$keys]);
        
        editText($message_id,"Пожалуйста, выберите желаемый сервер, чтобы добавить скидку агенту $userName Выбирать",$keys);
    }else alert("Серверов не осталось");
}
if(preg_match('/^removePercentOfAgent(?<type>Server|Plan)(?<agentId>\d+)_(?<serverId>\d+)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match['agentId']);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $discounts = json_decode($info['discount_percent'],true);
    if($match['type'] == "Server") unset($discounts['servers'][$match['serverId']]);
    elseif($match['type'] == "Plan") unset($discounts['plans'][$match['serverId']]);
    
    $discounts = json_encode($discounts,488);
    $stmt = $connection->prepare("UPDATE `users` SET `discount_percent` = ? WHERE `userid` = ?");
    $stmt->bind_param("si", $discounts, $match['agentId']);
    $stmt->execute();
    $stmt->close();
    
    alert('با موفقیت حذف شد');
    editText($message_id, str_replace("AGENT-NAME", $userName, $mainValues['agent_discount_settings']), getAgentDiscounts($match['agentId']));
}
if(preg_match('/^editAgentDiscount(Server|Plan|Normal)(\d+)_(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_agent_discount_percent'], $cancelKey);
    setUser($data);
}
if(preg_match('/^editAgentDiscount(Server|Plan|Normal)(\d+)_(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param('i',$match[2]);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $discountInfo = json_decode($info['discount_percent'],true);
        if($match[1] == "Server") $discountInfo['servers'][$match[3]] = $text;
        elseif($match[1] == "Plan") $discountInfo['plans'][$match[3]] = $text;
        elseif($match[1] == "Normal") $discountInfo['normal'] = $text;
        $text = json_encode($discountInfo);
        
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
        
        $stmt = $connection->prepare("UPDATE `users` SET `discount_percent` = ? WHERE `userid` = ?");
        $stmt->bind_param("si", $text, $match[2]);
        $stmt->execute();
        $stmt->close();
        sendMessage(str_replace("AGENT-NAME", $userName, $mainValues['agent_discount_settings']), getAgentDiscounts($match[2]));
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}

if($userInfo['phone'] == null && $from_id != $admin && $userInfo['isAdmin'] != true && $botState['requirePhone'] == "on"){
    if(isset($update->message->contact)){
        $contact = $update->message->contact;
        $phone_number = $contact->phone_number;
        $phone_id = $contact->user_id;
        if($phone_id != $from_id){
            sendMessage($mainValues['please_select_from_below_buttons']);
            exit();
        }else{
            if(!preg_match('/^\+98(\d+)/',$phone_number) && !preg_match('/^98(\d+)/',$phone_number) && !preg_match('/^0098(\d+)/',$phone_number) && $botState['requireIranPhone'] == 'on'){
                sendMessage($mainValues['use_iranian_number_only']);
                exit();
            }
            setUser($phone_number, 'phone');
            
            sendMessage($mainValues['phone_confirmed'],$removeKeyboard);
            $text = "/start";
        }
    }else{
        sendMessage($mainValues['send_your_phone_number'], json_encode([
			'keyboard' => [[[
					'text' => $buttonValues['send_phone_number'],
					'request_contact' => true,
				]]],
			'resize_keyboard' => true
		]));
		exit();
    }
}
if (preg_match('/^\/([Ss]tart)/', $text) or $text == $buttonValues['back_to_main'] or $data == 'mainMenu') {
    setUser();
    setUser("", "temp");
    if ($uinfo->num_rows == 0) {
        $first_name = !empty($first_name)?$first_name:" ";
        $username = !empty($username)?$username:" ";
        $refcode = time();
        $sql = "INSERT INTO `users` VALUES (NULL,?,?,?,?, 0,?)";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("issii", $from_id, $first_name, $username, $refcode, $time);
        $stmt->execute();
        $stmt->close();
    }
    if(isset($data) and $data == "mainMenu"){
        $res = editText($message_id, $mainValues['start_message'], getMainKeys());
        if(!$res->ok){
            sendMessage($mainValues['start_message'], getMainKeys());
            
        }
    }else{
        if($from_id != $admin && !isset($userInfo['first_start'])){
            setUser('sent','first_start');
            $keys = json_encode(['inline_keyboard'=>[
                [['text'=>$buttonValues['send_message_to_user'],'callback_data'=>'sendMessageToUser' . $from_id]]
            ]]);

            sendMessage(str_replace(["FULLNAME", "USERNAME", "USERID"], ["<a href='tg://user?id=$from_id'>$first_name</a>", $username, $from_id], $mainValues['new_member_joined'])
                ,$keys, "html",$admin);
        }
        sendMessage($mainValues['start_message'],getMainKeys());
    }
}
if(preg_match('/^sendMessageToUser(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    editText($message_id,'🔘Пожалуйста, отправьте ваше сообщение');
    setUser($data);
}
if(preg_match('/^sendMessageToUser(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    sendMessage($text,null,null,$match[1]);
    sendMessage("Ваше сообщение отправлено пользователю",$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    setUser();
}
if($data=='botReports' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id, "Статистика роботов на данный момент",getBotReportKeys());
}
if($data=="adminsList" && $from_id == $admin){
    editText($message_id, "Список администраторов",getAdminsKeys());
}
if(preg_match('/^delAdmin(\d+)/',$data,$match) && $from_id === $admin){
    $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = false WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    editText($message_id, "Список администраторов",getAdminsKeys());

}
if($data=="addNewAdmin" && $from_id === $admin){
    delMessage();
    sendMessage("🧑‍💻| Отправьте мне идентификационный номер человека, которого вы хотите администрировать: ",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addNewAdmin" && $from_id === $admin && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = true WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("✅ | 🥳 Что ж, пользователь теперь администратор, поздравляю ",$removeKeyboard);
        setUser();
        
        sendMessage("Список администраторов",getAdminsKeys());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(($data=="botSettings" or preg_match("/^changeBot(\w+)/",$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($data!="botSettings"){
        $newValue = $botState[$match[1]]=="on"?"off":"on";
        $botState[$match[1]]= $newValue;
        
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
        $stmt->execute();
        $isExists = $stmt->get_result();
        $stmt->close();
        if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
        else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
        $newData = json_encode($botState);
        
        $stmt = $connection->prepare($query);
        $stmt->bind_param("s", $newData);
        $stmt->execute();
        $stmt->close();
    }
    editText($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
if($data=="changeUpdateConfigLinkState" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $newValue = $botState['updateConnectionState']=="robot"?"site":"robot";
    $botState['updateConnectionState']= $newValue;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $isExists = $stmt->get_result();
    $stmt->close();
    if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
    else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
    $newData = json_encode($botState);
    
    $stmt = $connection->prepare($query);
    $stmt->bind_param("s", $newData);
    $stmt->execute();
    $stmt->close();

    editText($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
if(($data=="gateWays_Channels" or preg_match("/^changeGateWays(\w+)/",$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($data!="gateWays_Channels"){
        $newValue = $botState[$match[1]]=="on"?"off":"on";
        $botState[$match[1]]= $newValue;

        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
        $stmt->execute();
        $isExists = $stmt->get_result();
        $stmt->close();
        if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
        else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
        $newData = json_encode($botState);
        
        $stmt = $connection->prepare($query);
        $stmt->bind_param("s", $newData);
        $stmt->execute();
        $stmt->close();
    }
    editText($message_id,$mainValues['change_bot_settings_message'],getGateWaysKeys());
}
if($data=="changeConfigRemarkType"){
    switch($botState['remark']){
        case "digits":
            $newValue = "manual";
            break;
        case "manual":
            $newValue = "idanddigits";
            break;
        default:
            $newValue = "digits";
            break;
    }
    $botState['remark']= $newValue;

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $isExists = $stmt->get_result();
    $stmt->close();
    if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
    else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
    $newData = json_encode($botState);
    
    $stmt = $connection->prepare($query);
    $stmt->bind_param("s", $newData);
    $stmt->execute();
    $stmt->close();
    editText($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
if(preg_match('/^changePaymentKeys(\w+)/',$data,$match)){
    delMessage();
    switch($match[1]){
        case "nextpay":
            $gate = "Новый код NextPay";
            break;
        case "nowpayment":
            $gate = "Новый код порта nowPayment";
            break;
        case "zarinpal":
            $gate = "Новый код портала Zarin Pal";
            break;
        case "bankAccount":
            $gate = "Новый номер счета";
            break;
        case "holderName":
            $gate = "Имя владельца счета";
            break;
        case "tronwallet":
            $gate = "Адрес TRON";
            break;
    }
    sendMessage("🔘 Пожалуйста $gate входить", $cancelKey);
    setUser($data);
}
if(preg_match('/^changePaymentKeys(\w+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentInfo = $stmt->get_result();
    $stmt->close();
    $paymentKeys = json_decode($paymentInfo->fetch_assoc()['value'],true)??array();
    $paymentKeys[$match[1]] = $text;
    $paymentKeys = json_encode($paymentKeys);
    
    if($paymentInfo->num_rows > 0) $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'PAYMENT_KEYS'");
    else $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES ('PAYMENT_KEYS', ?)");
    $stmt->bind_param("s", $paymentKeys);
    $stmt->execute(); 
    $stmt->close();
    

    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    sendMessage($mainValues['change_bot_settings_message'],getGateWaysKeys());
    setUser();
}

if($data=="editRewardTime" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🙃 |Пожалуйста, укажите задержку отправки отчета на часы\n\n Примечание: каждые n часов отчет отправляется роботу! ",$cancelKey);
    setUser($data);
}
if($data=="userReports" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🙃 | Пожалуйста, введите числовой идентификатор пользователя ",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "userReports" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        sendMessage($mainValues['please_wait_message'],$removeKeyboard);
        $keys = getUserInfoKeys($text);
        if($keys != null){
            sendMessage("Информация о пользователе <a href='tg://user?id=$text'>$fullName</a>",$keys,"html");
            setUser();
        }else sendMessage("Пользователь с этим идентификатором не найден");
    }else{
        sendMessage("😡Пожалуйста, пришлите только номер");
    }
}
if($data=="inviteSetting" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
    $stmt->execute();
    $inviteAmount = number_format($stmt->get_result()->fetch_assoc()['value']??0) . "Мужчине";
    $stmt->close();
    setUser();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"❗️приглашение баннер",'callback_data'=>"inviteBanner"]],
        [
            ['text'=>$inviteAmount,'callback_data'=>"editInviteAmount"],
            ['text'=>"Размер комиссии",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"botSettings"]
            ],
        ]]); 
    $res = editText($message_id,"✅ Настройки маркетинга",$keys);
    if(!$res->ok){
        delMessage();
        sendMessage("✅ Настройки маркетинга",$keys);
    }
} 
if($data=="inviteBanner" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $inviteText = $stmt->get_result()->fetch_assoc()['value'];
    $inviteText = $inviteText != null?json_decode($inviteText,true):array('type'=>'text');
    $stmt->close();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"ویرایش",'callback_data'=>'editInviteBannerText']],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'inviteSetting']]
        ]]);
    if($inviteText['type'] == "text"){
        editText($message_id,"Текущий баннер: \n" . $inviteText['text'],$keys);
    }else{
        delMessage();
        $res = sendPhoto($inviteText['file_id'], $inviteText['caption'], $keys,null);
        if(!$res->ok){
            sendMessage("Текущее изображение не найдено, отредактируйте баннер.",$keys);
        }
    }
    setUser();
}
if($data=="editInviteBannerText" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🤖 |Пожалуйста, пришлите новый баннер из текста  LINK Используйте для отображения ссылки-приглашения)",$cancelKey);
    setUser($data);
}
if($userInfo['step']=="editInviteBannerText" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $data = array();
    if(isset($update->message->photo)){
        $data['type'] = 'photo';
        $data['caption'] = $caption;
        $data['file_id'] = $fileid;
    }
    elseif(isset($update->message->text)){
        $data['type'] = 'text';
        $data['text'] = $text;
    }else{
        sendMessage("🥺 | Размещенный баннер не поддерживается");
        exit();
    }
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $checkExist = $stmt->get_result();
    $stmt->close();
    $data = json_encode($data);
    if($checkExist->num_rows > 0){
        $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'INVITE_BANNER_TEXT'");
        $stmt->bind_param("s", $data);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("INSERT INTO `setting` (`value`, `type`) VALUES (?, 'INVITE_BANNER_TEXT')");
        $stmt->bind_param("s", $data);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
    }
    
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"ویرایش",'callback_data'=>'editInviteBannerText']],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'inviteSetting']]
        ]]);
    if(isset($update->message->text)){
        sendMessage("Текущий баннер: \n" . $text,$keys);
    }else{
        sendPhoto($fileid, $caption, $keys);
    }
    setUser();
}
if($data=="editInviteAmount" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("Пожалуйста, введите сумму комиссии в рублях",$cancelKey);
    setUser($data);
} 
if($userInfo['step'] == "editInviteAmount"){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
        
        if($checkExist->num_rows > 0){
            $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'INVITE_BANNER_AMOUNT'");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $checkExist = $stmt->get_result();
            $stmt->close();
        }else{
            $stmt = $connection->prepare("INSERT INTO `setting` (`value`, `type`) VALUES (?, 'INVITE_BANNER_AMOUNT')");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $checkExist = $stmt->get_result();
            $stmt->close();
        }
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
        
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"❗️приглашение баннер ",'callback_data'=>"inviteBanner"]],
            [
                ['text'=>number_format($text) . "Мужчине",'callback_data'=>"editInviteAmount"],
                ['text'=>"Размер комиссии",'callback_data'=>"wizwizch"]
                ], 
            [
                ['text'=>$buttonValues['back_button'],'callback_data'=>"botSettings"]
                ],
            ]]); 
        sendMessage("✅ Настройки маркетинга",$keys);
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}
if($userInfo['step'] == "editRewardTime" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("Пожалуйста, пришлите номер");
        exit();
    }
    elseif($text <0 ){
        sendMessage("Введенное значение недействительно");
        exit();
    }
    $botState['rewaredTime'] = $text;

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $isExist = $stmt->get_result();
    $stmt->close();
    if($isExist->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
    else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
    $newData = json_encode($botState);
    
    $stmt = $connection->prepare($query);
    $stmt->bind_param("s", $newData);
    $stmt->execute();
    $stmt->close();


    sendMessage($mainValues['change_bot_settings_message'],getBotSettingKeys());
    setUser();
    exit();
}
if($data=="inviteFriends"){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $inviteText = $stmt->get_result()->fetch_assoc()['value'];
    if($inviteText != null){
        delMessage();
        $inviteText = json_decode($inviteText,true);
    
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $inviteAmount = number_format($stmt->get_result()->fetch_assoc()['value']??0) . " Мужчине";
        $stmt->close();
        
        $getBotInfo = json_decode(file_get_contents("http://api.telegram.org/bot" . $botToken . "/getMe"),true);
        $botId = $getBotInfo['result']['username'];
        
        $link = "t.me/$botId?start=" . $from_id;
        if($inviteText['type'] == "text"){
            $txt = str_replace('LINK',"<code>$link</code>",$inviteText['text']);
            $res = sendMessage($txt,null,"HTML");
        } 
        else{
            $txt = str_replace('LINK',"$link",$inviteText['caption']);
            $res = sendPhoto($inviteText['file_id'],$txt,null,"HTML");
        }
        $msgId = $res->result->message_id;
        sendMessage("Приглашайте своих друзей в робота по ссылке выше и при каждой покупке. $inviteAmount получать",json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),null,null,$msgId);
    }
    else alert("Этот раздел неактивен");
}
if($data=="myInfo"){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ?");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $totalBuys = $stmt->get_result()->num_rows;
    $stmt->close();
    
    $myWallet = number_format($userInfo['wallet']) . " Мужчине";
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"Пополнить кошелек 💰",'callback_data'=>"increaseMyWallet"],
            ['text'=>"Перенос инвентаря",'callback_data'=>"transferMyWallet"]
        ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]
            ]
        ]]);
    editText($message_id, "
💞 Информация о вашей учетной записи:
    
🔰 имя пользователя: <code> $from_id </code>
🍄 Имя пользователя: <code> @$username </code>
👤 имя:  <code> $first_name </code>
💰 инвентарь: <code> $myWallet </code>

👈🏻 Все услуги : <code> $totalBuys </code> число
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
",
            $keys,"html");
}
if($data=="transferMyWallet"){
    if($userInfo['wallet'] > 0 ){
        delMessage();
        sendMessage("Пожалуйста, введите цифровой идентификатор желаемого пользователя",$cancelKey);
        setUser($data);
    }else alert("Баланс вашего счета низкий");
}
if($userInfo['step'] =="transferMyWallet" && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text != $from_id){
            $stmt= $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
            $stmt->bind_param("i", $text);
            $stmt->execute();
            $checkExist = $stmt->get_result();
            $stmt->close();
            
            if($checkExist->num_rows > 0){
                setUser("tranfserUserAmount" . $text);
                sendMessage("Пожалуйста, введите желаемую сумму");
            }else sendMessage("Пользователь с этим идентификатором не найден");
        }else sendMessage("Хотите перевестись на себя?");
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^tranfserUserAmount(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text > 0){
            if($userInfo['wallet'] >= $text){
                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                $stmt->bind_param("ii", $text, $match[1]);
                $stmt->execute();
                $stmt->close();
                
                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
                $stmt->bind_param("ii", $text, $from_id);
                $stmt->execute();
                $stmt->close();
                
                sendMessage("✅|Количество " . number_format($text) . " Токены на ваш кошелек пользователем $from_id переведен",null,null,$match[1]);
                setUser();
                sendMessage("✅|Количество " . number_format($text) . " Токены были переведены на кошелек желаемого пользователя",$removeKeyboard);
                sendMessage("Пожалуйста, выберите один из ключей ниже",getMainKeys());
            }else sendMessage("Баланс вашего счета низкий");
        }else sendMessage("Пожалуйста, введите число больше нуля");
    }else sendMessage($mainValues['send_only_number']);
}
if($data=="increaseMyWallet"){
    delMessage();
    sendMessage("🙂 Пожалуйста, введите желаемую сумму в токенах (более 5000 токенов)",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "increaseMyWallet" && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }
    elseif($text < 5000){
        sendMessage("Пожалуйста, введите сумму больше 5000");
        exit();
    }
    sendMessage("🪄 пожалуйста, будьте терпеливы ...",$removeKeyboard);
    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'INCREASE_WALLET' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
    
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, 'INCREASE_WALLET', '0', '0', '0', ?, ?, 'pending')");
    $stmt->bind_param("siii", $hash_id, $from_id, $text, $time);
    $stmt->execute();
    $stmt->close();
    
    
    $keyboard = array();
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "increaseWalletWithCartToCart" . $hash_id]];
    if($botState['nowPaymentWallet'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];

    
	$keys = json_encode(['inline_keyboard'=>$keyboard]);
    sendMessage("Информация о зарядке:\nКоличество". number_format($text) . "Токен\n\nПожалуйста, выберите способ оплаты",$keys);
    setUser();
}
if(preg_match('/increaseWalletWithCartToCart/',$data)) {
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
    else $paymentKeys = array();
    $stmt->close();

    delMessage();  
    setUser($data);
    

    sendMessage(str_replace(["ACCOUNT-NUMBER", "HOLDER-NAME"],[$paymentKeys['bankAccount'],$paymentKeys['holderName']], $mainValues['increase_wallet_cart_to_cart']),$cancelKey, "HTML");
    exit;
}
if(preg_match('/increaseWalletWithCartToCart(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']){
    if(isset($update->message->photo)){
        setUser();
        $uid = $userInfo['userid'];
        $name = $userInfo['name'];
        $username = $userInfo['username'];
    
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $price = number_format($payInfo['price']);

    

        sendMessage($mainValues['order_increase_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        $msg = str_replace(['PRICE', 'USERNAME', 'NAME', 'USER-ID'],[$price, $username, $name, $from_id], $mainValues['increase_wallet_request_message']);
        
        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "approvePayment{$match[1]}"],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decPayment{$match[1]}"]
                ]
            ]
        ]);
        sendPhoto($fileid, $msg,$keyboard, "HTML", $admin);
    }else{
        sendMessage($mainValues['please_send_only_image']);
    }
}
if(preg_match('/^approvePayment(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $payInfo['price'];
    $userId = $payInfo['user_id'];
    
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
    

    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
    $stmt->bind_param("ii", $price, $userId);
    $stmt->execute();
    $stmt->close();

    sendMessage("Пополнение вашего счета успешно подтверждено\n✅ Количество " . number_format($price). " Токены добавлены в ваш аккаунт",null,null,$userId);
    
    unset($markup[count($markup)-1]);
    $markup[] = [['text' => '✅', 'callback_data' => "dontsendanymore"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);

    editKeys($keys);
}
if(preg_match('/^decPayment(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    unset($markup[count($markup)-1]);
    $markup[] = [['text' => '❌', 'callback_data' => "dontsendanymore"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);
    file_put_contents("temp" . $from_id . ".txt", $keys);
    sendMessage("Пожалуйста, укажите причину неподтверждения увеличения запасов",$cancelKey);
    setUser("decPayment" . $message_id . "_" . $match[1]);
}
if(preg_match('/^decPayment(\d+)_(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[2]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $price = $payInfo['price'];
    $userId = $payInfo['user_id'];
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'declined' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[2]);
    $stmt->execute();
    $stmt->close();
    
    sendMessage("💔 Увеличьте свой баланс на сумму"  . number_format($price) . " Отклонено по следующей причине\n\n$text",null,null,$userId);


    editKeys(file_get_contents("temp" . $from_id . ".txt"), $match[1]);
    setUser();
    sendMessage('Я отправил ему твое сообщение... 🤝',$removeKeyboard);
    unlink("temp" . $from_id . ".txt");
}
if($data=="increaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'],$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "increaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $userCount = $stmt->get_result()->num_rows;
        $stmt->close();
        if($userCount > 0){
            setUser("increaseWalletUser" . $text);
            sendMessage($mainValues['enter_increase_amount']);
        }
        else{
            setUser();
            sendMessage($mainValues['user_not_found'], $removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match('/^increaseWalletUser(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
    
        sendMessage("✅ Количество " . number_format($text). " Токены добавлен в ваш аккаунт",null,null,$match[1]);
        sendMessage("✅ Количество " . number_format($text) . "Токены добавлены в кошелек предполагаемого пользователя",$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        setUser();
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if($data=="decreaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'],$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "decreaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $userCount = $stmt->get_result()->num_rows;
        $stmt->close();
        if($userCount > 0){
            setUser("decreaseWalletUser" . $text);
            sendMessage($mainValues['enter_decrease_amount']);
        }
        else{
            setUser();
            sendMessage($mainValues['user_not_found'], $removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match('/^decreaseWalletUser(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
    
        sendMessage(str_replace("AMOUNT", number_format($text), $mainValues['amount_decreased_from_your_wallet']),null,null,$match[1]);
        sendMessage(str_replace("AMOUNT", number_format($text), $mainValues['amount_decreased_from_user_wallet']),$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        setUser();
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if($data=="editRewardChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🤗 Пожалуйста, администрируйте робота на канале и отправьте идентификатор канала.",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "editRewardChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $botId = json_decode(file_get_contents("https://api.telegram.org/bot$botToken/getme"))->result->id;
    $result = json_decode(file_get_contents("https://api.telegram.org/bot$botToken/getChatMember?chat_id=$text&user_id=$botId"));
    if($result->ok){
        if($result->result->status == "administrator"){
            $botState['rewardChannel'] = $text;
            
            $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
            $stmt->execute();
            $isExist = $stmt->get_result();
            $stmt->close();
            if($isExist->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
            else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
            $newData = json_encode($botState);
            
            $stmt = $connection->prepare($query);
            $stmt->bind_param("s", $newData);
            $stmt->execute();
            $stmt->close();
            
            sendMessage($mainValues['change_bot_settings_message'],getGateWaysKeys());
            setUser();
            exit();
        }
    }
    sendMessage("😡 Botfather еще не присоединился к каналу, сначала администрируй робота в канале и отправь его идентификатор.");
}
if($data=="editLockChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🤗|Пожалуйста, администрируйте робота на канале и отправьте идентификатор канала.",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "editLockChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $botId = json_decode(file_get_contents("https://api.telegram.org/bot$botToken/getme"))->result->id;
    $result = json_decode(file_get_contents("https://api.telegram.org/bot$botToken/getChatMember?chat_id=$text&user_id=$botId"));
    if($result->ok){
        if($result->result->status == "administrator"){
            $botState['lockChannel'] = $text;

            $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
            $stmt->execute();
            $isExists = $stmt->get_result();
            $stmt->close();
            if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
            else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
            $newData = json_encode($botState);
            
            $stmt = $connection->prepare($query);
            $stmt->bind_param("s", $newData);
            $stmt->execute();
            $stmt->close();
            
            sendMessage($mainValues['change_bot_settings_message'],getGateWaysKeys());
            setUser();
            exit();
        }
    }
    sendMessage($mainValues['the_bot_in_not_admin']);
}
if (($data == "agentOneBuy" || $data=='buySubscription' || $data == "agentMuchBuy") && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true))){
    if($botState['cartToCartState'] == "off" && $botState['walletState'] == "off"){
        alert($mainValues['selling_is_off']);
        exit();
    }
    if($data=="buySubscription") setUser('','temp');
    elseif($data=="agentOneBuy") setUser('agentBuy', 'temp');
    elseif($data== "agentMuchBuy") setUser('agentMuchBuy', 'temp');
    
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 and `state` = 1 and `ucount` > 0 ORDER BY `id` ASC");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert($mainValues['no_server_available']);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $flag = $cat['flag'];
        $keyboard[] = ['text' => "$flag $name", 'callback_data' => "selectServer$id"];
    }
    $keyboard = array_chunk($keyboard,1);
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];
    editText($message_id, $mainValues['buy_sub_select_location'], json_encode(['inline_keyboard'=>$keyboard]));
}
if ($data=='createMultipleAccounts' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 and `ucount` > 0 ORDER BY `id` ASC");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        sendMessage($mainValues['no_server_available']);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $flag = $cat['flag'];
        $keyboard[] = ['text' => "$flag $name", 'callback_data' => "createAccServer$id"];
    }
    $keyboard[] = ['text'=>$buttonValues['back_to_main'],'callback_data'=>"managePanel"];
    $keyboard = array_chunk($keyboard,1);
    editText($message_id, $mainValues['buy_sub_select_location'], json_encode(['inline_keyboard'=>$keyboard]));
    

}
if(preg_match('/createAccServer(\d+)/',$data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) ) {
    $sid = $match[1];
        
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent`=0 order by `id` asc");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert("Для этого сервера нет категорий");
    }else{
        
        $keyboard = [];
        while ($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1");
            $stmt->bind_param("ii", $sid, $id);
            $stmt->execute();
            $rowcount = $stmt->get_result()->num_rows; 
            $stmt->close();
            if($rowcount>0) $keyboard[] = ['text' => "$name", 'callback_data' => "createAccCategory{$id}_{$sid}"];
        }
        if(empty($keyboard)){
            alert("Для этого сервера нет категорий");exit;
        }
        alert("♻️ | Получить категории ...");
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "createMultipleAccounts"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, "2️⃣  Шаг второй: выберите нужную категорию 🤭", json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/createAccCategory(\d+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match[1];
    $sid = $match[2];
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1 order by `id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert("💡В этой категории нет контента ");
    }else{
        alert("📍Получение списка планов");
        $keyboard = [];
        while($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $keyboard[] = ['text' => "$name", 'callback_data' => "createAccPlan{$id}"];
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "createAccServer$sid"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, "3️⃣ Шаг третий: Выберите один из пакетов и переходите к оплате 🤲 🕋", json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/^createAccPlan(\d+)/',$data,$match) && $text != $buttonValues['cancel']){
    delMessage();
    sendMessage("❗️Введите период в днях.:",$cancelKey);
    setUser('createAccDate' . $match[1]);
}
if(preg_match('/^createAccDate(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text >0){
            sendMessage("❕Введите объем траффика в Gb:");
            setUser('createAccVolume' . $match[1] . "_" . $text);
        }else{
            sendMessage("Число должно быть больше 0");
        }
    }else{
        sendMessage('😡 | Разве я не говорю: просто спроси номер, ты не понимаешь? Или ты обманул себя?');
    }
}
if(preg_match('/^createAccVolume(\d+)_(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }elseif($text <=0){
        sendMessage("Введите значение больше 0");
        exit();
    }
    sendMessage($mainValues['enter_account_amount']);
    setUser("createAccAmount" . $match[1] . "_" . $match[2] . "_" . $text);
}
if(preg_match('/^createAccAmount(\d+)_(\d+)_(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }elseif($text <=0){
        sendMessage("Введите значение больше 0");
        exit();
    }
    $uid = $from_id;
    $fid = $match[1];
    $acctxt = '';
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = $match[2];
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $volume = $match[3];
    $protocol = $file_detail['protocol'];
    $price = $file_detail['price'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    
    
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];


    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }else{
        if($acount < $text) {
            sendMessage(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
            exit();
        }
    }

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $srv_remark = $serverInfo['remark'];
    $stmt->close();
    $savedinfo = file_get_contents('settings/temp.txt');
    $savedinfo = explode('-',$savedinfo);
    $port = $savedinfo[0];
    $last_num = $savedinfo[1];
    include 'phpqrcode/qrlib.php';
    $ecc = 'L';
    $pixel_Size = 11;
    $frame_Size = 0;
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $portType = $stmt->get_result()->fetch_assoc()['port_type'];
    $stmt->close();


	$stmt = $connection->prepare("INSERT INTO `orders_list` 
	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`)
	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?);");
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);
    for($i = 1; $i<= $text; $i++){
        $token = RandomString(30);
        $uniqid = generateRandomString(42,$protocol); 
        if($portType == "auto"){
            $port++;
        }else{
            $port = rand(1111,65000);
        }
        $last_num++;
        
        if($botState['remark'] == "digits"){
            $rnd = rand(10000,99999);
            $remark = "{$srv_remark}-{$rnd}";
        }else{
            $rnd = rand(1111,99999);
            $remark = "{$srv_remark}-{$from_id}-{$rnd}";
        }
    
        if($inbound_id == 0){    
            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
        }else {
            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
        }
        
        if(is_null($response)){
            sendMessage('❌ | 🥺 Голам, соединение с сервером не установлено, сообщите об этом администратору ...');
            break;
        }
    	if($response == "inbound not Found"){
            sendMessage("❌ | 🥺 Линия (inbound) с идентификатором $inbound_id Его не существует на этом сервере, сообщите администратору...");
            break;
    	}
    	if(!$response->success){
            sendMessage('❌ | 😮 Ого, произошла ошибка, пожалуйста, срочно сообщите менеджеру. ...');
            sendMessage("Ошибка сервера {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
            break;
        }
    
        $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
        $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";
        foreach($vraylink as $vray_link){
            $acc_text = "
    
        🔮 $remark \n " . ($botState['configLinkState'] != "off"?"<code>$vray_link</code>":"");
            if($botState['subLinkState'] == "on") $acc_text .= 
            " \n🌐 subscription : <code>$subLink</code>";
        
            $file = RandomString() .".png";
            
            QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
        	addBorderImage($file);
        	
        	
        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
            $qrImage = imagecreatefrompng($file);
            
            $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
            imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
            imagepng($backgroundImage, $file);
            imagedestroy($backgroundImage);
            imagedestroy($qrImage);


        	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
            unlink($file);
        }
        $vray_link = json_encode($vraylink);
        $stmt->bind_param("ssiiisssisiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $price, $date, $rahgozar);
        $stmt->execute();
    }
    $stmt->close();
    if($portType == "auto"){
        file_put_contents('settings/temp.txt',$port.'-'.$last_num);
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE id=?");
        $stmt->bind_param("ii", $text, $fid);
        $stmt->execute();
        $stmt->close();
    }
    sendMessage("☑️|❤️ Новые учетные записи успешно созданы",getMainKeys());
    setUser();
}
if(preg_match('/payWithTronWallet(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $fid = $payInfo['plan_id'];
    $type = $payInfo['type'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];

    if($type != "INCREASE_WALLET" && $type != "RENEW_ACCOUNT"){
        if($acount <= 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit; 
            }
        }else{
            if($acount <= 0){
                alert($mainValues['out_of_server_capacity']);
                exit();
            }
        }
    }
    
    if($type == "RENEW_ACCOUNT"){
        $oid = $payInfo['plan_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result();
        $stmt->close();
        if($order->num_rows == 0){
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys());
            exit();
        }

    }
    
    delMessage();
    
    $price = $payInfo['price'];
    $priceInTrx = round($price / $botState['TRXRate'],2);
    
    $stmt = $connection->prepare("UPDATE `pays` SET `tron_price` = ? WHERE `hash_id` = ?");
    $stmt->bind_param("ds", $priceInTrx, $match[1]);
    $stmt->execute();
    $stmt->close();
    
    sendMessage(str_replace(["AMOUNT", "TRON-WALLET"], [$priceInTrx, $paymentKeys['tronwallet']], $mainValues['pay_with_tron_wallet']), $cancelKey, "html");
    setUser($data);
}
if(preg_match('/^payWithTronWallet(.*)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if(!preg_match('/^[0-9a-f]{64}$/i',$text)){
        sendMessage($mainValues['incorrect_tax_id']);
        exit(); 
    }else{
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
        
        if($checkExist->num_rows == 0){
            $stmt = $connection->prepare("UPDATE `pays` SET `payid` = ?, `state` = '0' WHERE `hash_id` = ?");
            $stmt->bind_param("ss", $text, $match[1]);
            $stmt->execute();
            $stmt->close();
            
            sendMessage($mainValues['in_review_tax_id'], $removeKeyboard);
            setUser();
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }else sendMessage($mainValues['used_tax_id']);
    }

}
if(preg_match('/payWithWeSwap(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $fid = $payInfo['plan_id'];
    $type = $payInfo['type'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];

    if($type != "INCREASE_WALLET" && $type != "RENEW_ACCOUNT"){
        if($acount <= 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit; 
            }
        }else{
            if($acount <= 0){
                alert($mainValues['out_of_server_capacity']);
                exit();
            }
        }
    }
    
    if($type == "RENEW_ACCOUNT"){
        $oid = $payInfo['plan_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result();
        $stmt->close();
        if($order->num_rows == 0){
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys());
            exit();
        }

    }
    
    delMessage();
    sendMessage($mainValues['please_wait_message'],$removeKeyboard);
    
    
    $price = $payInfo['price'];
    $priceInUSD = round($price / $botState['USDRate'],2);
    $priceInTrx = round($price / $botState['TRXRate'],2);
    $pay = NOWPayments('POST', 'payment', [
        'price_amount' => $priceInUSD,
        'price_currency' => 'usd',
        'pay_currency' => 'trx'
    ]);
    if(isset($pay->pay_address)){
        $payAddress = $pay->pay_address;
        
        $payId = $pay->payment_id;
        
        $stmt = $connection->prepare("UPDATE `pays` SET `payid` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("is", $payId, $match[1]);
        $stmt->execute();
        $stmt->close();
        
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"Оплата через валютный портал риал",'url'=>"https://changeto.technology/quick?amount=$priceInTrx&currency=TRX&address=$payAddress"]],
            [['text'=>"я заплатил ✅",'callback_data'=>"havePaiedWeSwap" . $match[1]]]
            ]]);
sendMessage("
✅ Ссылка для оплаты успешно создана

💰Количество : " . $priceInTrx . " Понимаете

✔️ После оплаты подождите от 1 до 15 минут, пока платеж не будет произведен в полном объеме, затем нажмите «Я заплатил».
⁮⁮ ⁮⁮
",$keys);
    }else{
        if($pay->statusCode == 400){
            sendMessage("Выбранное значение меньше предельного");
        }else{
            sendMessage("Что-то пошло не так, сообщите в службу поддержки");
        }
        sendMessage("Пожалуйста, выберите один из ключей ниже",getMainKeys());
    }
}
if(preg_match('/havePaiedWeSwap(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if($payInfo['state'] == "pending"){
    $payid = $payInfo['payid'];
    $payType = $payInfo['type'];
    $price = $payInfo['price'];

    $request_json = NOWPayments('GET', 'payment', $payid);
    if($request_json->payment_status == 'finished' or $request_json->payment_status == 'confirmed' or $request_json->payment_status == 'sending'){
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
        
    if($payType == "INCREASE_WALLET"){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $from_id);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("Пополнение вашего счета успешно подтверждено\n✅ " . number_format($price). " токенов добавлено на вас счет");
        sendMessage("✅ Сумма " . number_format($price) . " токенов на кошелек пользователя $from_id добавлено валютным шлюзом",null,null,$admin);                
    }
    elseif($payType == "BUY_SUB"){
    $uid = $from_id;
    $fid = $payInfo['plan_id']; 
    $volume = $payInfo['volume'];
    $days = $payInfo['day'];
    $description = $payInfo['description'];
    
    
    $acctxt = '';
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if($volume == 0 && $days == 0){
        $volume = $file_detail['volume'];
        $days = $file_detail['days'];
    }
    
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $protocol = $file_detail['protocol'];
    $price = $payInfo['price'];   
    
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    $accountCount = $payInfo['agent_count']!=0?$payInfo['agent_count']:1;
    $eachPrice = $price / $accountCount;
    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $serverTitle = $serverInfo['title'];
    $srv_remark = $serverInfo['remark'];
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $portType = $stmt->get_result()->fetch_assoc()['port_type'];
    $stmt->close();
    include 'phpqrcode/qrlib.php';

    alert($mainValues['sending_config_to_user']);
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);
    for($i = 1; $i <= $accountCount; $i++){
        $uniqid = generateRandomString(42,$protocol);
        
        $savedinfo = file_get_contents('settings/temp.txt');
        $savedinfo = explode('-',$savedinfo);
        $port = $savedinfo[0] + 1;
        $last_num = $savedinfo[1] + 1;
        
        if($botState['remark'] == "digits"){
            $rnd = rand(10000,99999);
            $remark = "{$srv_remark}-{$rnd}";
        }
        elseif($botState['remark'] == "manual"){
            $remark = $payInfo['description'];
        }
        else{
            $rnd = rand(1111,99999);
            $remark = "{$srv_remark}-{$from_id}-{$rnd}";
        }
        if(!empty($description)) $remark = $description;
        if($portType == "auto"){
            file_put_contents('settings/temp.txt',$port.'-'.$last_num);
        }else{
            $port = rand(1111,65000);
        }
        
        if($inbound_id == 0){    
            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
            if(!$response->success){
                $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
            } 
        }else {
            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
            if(!$response->success){
                $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
            } 
        }
        
        if(is_null($response)){
            sendMessage('❌ | 🥺 Голам, соединение с сервером не установлено, сообщите об этом администратору ...');
            exit;
        }
        if($response == "inbound not Found"){
            sendMessage("❌ | 🥺 Линия (inbound) с идентификатором $inbound_id не существует на этом сервере, сообщите менеджеру...");
        	exit;
        }
        if(!$response->success){
            sendMessage('❌ | 😮 Ого, произошла ошибка, пожалуйста, срочно сообщите менеджеру. ...');
            sendMessage("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
            exit;
        }
        
        $token = RandomString(30);
        $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";

        $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
        foreach($vraylink as $vray_link){
        $acc_text = "😍 Ваш новый заказ
📡 Протокол: $protocol
🔮 Имя сервиса: $remark
🔋 Объем траффика: $volume Гб.
⏰ Период действия: $days дней.
" . ($botState['configLinkState'] != "off"?"
💝 config : <code>$vray_link</code>":"");

if($botState['subLinkState'] == "on") $acc_text .= "

🔋 Volume web: <code> $botUrl"."search.php?id=".$uniqid."</code>


🌐 subscription : <code>$subLink</code>
        
        ";
              
            $file = RandomString() .".png";
            $ecc = 'L';
            $pixel_Size = 11;
            $frame_Size = 0;
            
            QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
        	addBorderImage($file);
        	
        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
            $qrImage = imagecreatefrompng($file);
            
            $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
            imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
            imagepng($backgroundImage, $file);
            imagedestroy($backgroundImage);
            imagedestroy($qrImage);

        	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
            unlink($file);
        }
        
        $vray_link = json_encode($vraylink);
        $agentBought = $payInfo['agent_bought'];
        
        $stmt = $connection->prepare("INSERT INTO `orders_list` 
            (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
            VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
        $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $date, $rahgozar, $agentBought);
        $stmt->execute();
        $order = $stmt->get_result(); 
        $stmt->close();
    }
    
    if($userInfo['refered_by'] != null){
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
        $stmt->close();
        $inviterId = $userInfo['refered_by'];
        
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $inviteAmount, $inviterId);
        $stmt->execute();
        $stmt->close();
         
        sendMessage("Поздравляем, одно из ваших подмножеств совершило покупку. " . number_format($inviteAmount) . " Вы получили вознаграждение в размере ",null,null,$inviterId);
    }
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"Я хочу купить новый ❤️",'callback_data'=>"wizwizch"]
        ],
        ]]);
        
    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - ? WHERE `id`=?");
        $stmt->bind_param("ii", $accountCount, $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE id=?");
        $stmt->bind_param("ii", $accountCount, $fid);
        $stmt->execute();
        $stmt->close();
    }
    $msg = str_replace(['SERVERNAME', 'TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                [$serverTitle, 'Риал валюта', $from_id, $username, $first_name, $price, $remark,$volume, $days], $mainValues['buy_new_account_request']);
    
    sendMessage($msg,$keys,"html", $admin);
}
elseif($payType == "RENEW_ACCOUNT"){
    $oid = $payInfo['plan_id'];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $fid = $order['fileid'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $server_id = $order['server_id'];
    $inbound_id = $order['inbound_id'];
    $expire_date = $order['expire_date'];
    $expire_date = ($expire_date > $time) ? $expire_date : $time;
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $name = $respd['title'];
    $days = $respd['days'];
    $volume = $respd['volume'];
    $price = $payInfo['price'];
    
    if($inbound_id > 0)
        $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
    else
        $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
    
    if(is_null($response)){
    	alert('🔻Техническая проблема с подключением к серверу. Пожалуйста, сообщите руководству',true);
    	exit;
    }
    $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
    $newExpire = $time + $days * 86400;
    $stmt->bind_param("ii", $newExpire, $oid);
    $stmt->execute();
    $stmt->close();
    $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
    $stmt->execute();
    $stmt->close();

sendMessage("✅Услуга $remark успешно продлена.",getMainKeys());
$keys = json_encode(['inline_keyboard'=>[
    [
        ['text'=>"расширить 😍",'callback_data'=>"wizwizch"]
        ],
    ]]);

    $msg = str_replace(['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],['кошелек', $from_id, $username, $first_name, $price, $remark, $volume, $days], $mainValues['renew_account_request_message']);

sendMessage($msg, $keys,"html", $admin);
}
elseif(preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType, $increaseInfo)){
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    $uuid = $orderInfo['uuid']??"0";
    
    $planid = $increaseInfo[2];

    
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $payInfo['price'];
    $volume = $res['volume'];


if($inbound_id > 0)
    $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
else
    $response = editInboundTraffic($server_id, $uuid, 0, $volume);
    
if($response->success){
    $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
    $newVolume = $volume * 86400;
    $stmt->bind_param("is", $newVolume, $uuid);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    $newVolume = $volume * 86400;
    $stmt->bind_param("iiisii", $from_id, $server_id, $inbound_id, $remark, $price, $time);
    $stmt->execute();
    $stmt->close();
    
    sendMessage("✅$volume Дней, добавленных к продолжительности вашего обслуживания",getMainKeys());
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"Это заняло некоторое время 😁",'callback_data'=>"wizwizch"]
            ],
        ]]);
sendMessage("🔋|💰 Увеличить период действия с помощью кошелька

От пользователя: $from_id
Имя : $first_name
⚡️ Имя пользователя: $username
🎈 Имя сервиса: $remark
⏰ Период увеличения: $volume дней.
💰Цена: $price Токенов
⁮⁮ ⁮⁮
",$keys,"html", $admin);

    exit;
}else {
    alert("По техническим причинам увеличить время невозможно. Пожалуйста, сообщите руководству или повторите тест через 5 минут.", true);
    exit;
}
}
elseif(preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo)){
$orderId = $increaseInfo[1];

$stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$orderInfo = $stmt->get_result()->fetch_assoc();
$stmt->close();

$server_id = $orderInfo['server_id'];
$inbound_id = $orderInfo['inbound_id'];
$remark = $orderInfo['remark'];
$uuid = $orderInfo['uuid']??"0";

$planid = $increaseInfo[2];

$stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
$stmt->bind_param("i", $planid);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
$price = $payInfo['price'];
$volume = $res['volume'];

if($inbound_id > 0)
    $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0);
else
    $response = editInboundTraffic($server_id, $uuid, $volume, 0);
    
if($response->success){
    $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
    $stmt->bind_param("s", $uuid);
    $stmt->execute();
    $stmt->close();
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"Наконец-то один том увеличился 😁",'callback_data'=>"wizwizch"]
            ],
        ]]);
sendMessage("🔋|💰 увеличение объема траффика с помощью кошелька

От пользователя: $from_id
Имя: $first_name
⚡️ Имя пользователя: $username
🎈 Имя сервиса: $remark
🔋 Увеличение траффика: $volume Гб
💰Цена: $price Токенов
⁮⁮ ⁮⁮
",$keys,"html", $admin);
    sendMessage( "✅$volume Гб добавлено к объему вашего траффика",getMainKeys());exit;
    

}else {
    alert("По техническим причинам увеличить громкость невозможно. Пожалуйста, сообщите руководству или повторите тест через 5 минут.",true);
    exit;
}
}
elseif($payType == "RENEW_SCONFIG"){
    $uid = $from_id;
    $fid = $payInfo['plan_id']; 

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $volume = $file_detail['volume'];
    $days = $file_detail['days'];
    
    $price = $payInfo['price'];   
    $server_id = $file_detail['server_id'];
    $configInfo = json_decode($payInfo['description'],true);
    $remark = $configInfo['remark'];
    $uuid = $configInfo['uuid'];
    
    $remark = $payInfo['description'];
    $inbound_id = $payInfo['volume']; 
    
    if($inbound_id > 0)
        $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
    else
        $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
    
	if(is_null($response)){
		alert('🔻Техническая проблема с подключением к серверу. Пожалуйста, сообщите руководству',true);
		exit;
	}
	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
	$stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
	$stmt->execute();
	$stmt->close();

    sendMessage("🔋|💰 Продление пакета с помощью кошелька
    
     От пользователя: $from_id
     Имя: $first_name
     ⚡️ Имя пользователя: $username
     🎈 Имя сервиса: $remark
     🔋 Объем траффика: $volume Гб.
     ⏰ Период действия: $days дней.
     💰Цена: $price Токенов
     ⁮⁮ ⁮⁮
    ",$keys,"html", $admin);

}
    
    editKeys(json_encode(['inline_keyboard'=>[
		    [['text'=>"Оплата произведена",'callback_data'=>"wizwizch"]]
		    ]]));
}else{
    if($request_json->payment_status == 'partially_paid'){
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'partiallyPaied' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $stmt->close();
        alert("Вы недоплатили, пожалуйста, напишите в службу поддержки");
    }else{
        alert("Желаемый платеж еще не завершен!");
    }
}
}else alert("Срок действия этой платежной ссылки истек");
}
if($data=="messageToSpeceficUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'], $cancelKey);
    setUser($data);
}
if($userInfo['step'] == "messageToSpeceficUser" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param("i", $text);
    $stmt->execute();
    $usersCount = $stmt->get_result()->num_rows;
    $stmt->close();

    if($usersCount > 0 ){
        sendMessage("👀| Хочешь отправить ему личное сообщение? Отправь свое сообщение, чтобы я мог передать ему:");
        setUser("sendMessageToUser" . $text);
    }else{
        sendMessage($mainValues['user_not_found']);
    }
}
if ($data == 'message2All' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $sendInfo = json_decode(file_get_contents("settings/messagewizwiz.json"),true);
    $offset = $sendInfo['offset'];
    $msg = $sendInfo['text'];
    
    if(strlen($msg) > 1 and $offset != -1) {
        $stmt = $connection->prepare("SELECT * FROM `users`");
        $stmt->execute();
        $usersCount = $stmt->get_result()->num_rows;
        $stmt->close();
        
        $leftMessages = $offset == 0 ? $usersCount - $offset : $usersCount - $offset;
        $offset = $offset == 0 ? $offset : $offset;
        
        if(json_decode($sendInfo['text'],true)['type'] == "forwardall"){
            sendMessage("❗️ Публичный релиз находится в очереди на выпуск, пожалуйста, наберитесь терпения...
            
             🔰 Количество пользователей: $usersCount
             ☑️ Импортировано: $offset
             📣 Осталось: $leftMessages
             ⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
            ");
        }else{
            sendMessage("❗️ Публичное сообщение находится в очереди на публикацию, наберитесь терпения...
            
             🔰 Количество пользователей: $usersCount
             ☑️ Отправлено: $offset
             📣 Осталось: $leftMessages
             ⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
            ");
        }
        exit;
    }
    setUser('s2a');
    sendMessage("Напишите пожалуйста свое сообщение, я хочу отправить его всем: 🙂",$cancelKey);
    exit;
}
if ($userInfo['step'] == 's2a' and $text != $buttonValues['cancel']){
    setUser();
    sendMessage('⏳ Спасибо за ваше сообщение  ...  ',$removeKeyboard);
    sendMessage("Отправить всем?",json_encode(['inline_keyboard'=>[
    [['text'=>"Отправлять",'callback_data'=>"yesSend2All"],['text'=>"не отправил",'callback_data'=>"noDontSend2all"]]
    ]]));

    if($fileid !== null) {
        $value = ['fileid'=>$fileid,'caption'=>$caption];
        $type = $filetype;
    }
    else{
        $type = 'text';
        $value = $text;
    }
    $messageValue = json_encode(['type'=>$type,'value'=> $value]);
    
    $sendInfo = json_decode(file_get_contents("settings/messagewizwiz.json"),true);
    $sendInfo['text'] = $messageValue;
    file_put_contents("settings/messagewizwiz.json",json_encode($sendInfo));
}
if($data=="noDontSend2all"){
    editText($message_id,'Отправка публичного сообщения отменена',getMainKeys());
}
if($data=="yesSend2All"){
    $sendInfo = json_decode(file_get_contents("settings/messagewizwiz.json"),true);
    $sendInfo['offset'] = 0;
    file_put_contents("settings/messagewizwiz.json",json_encode($sendInfo));
 
    editText($message_id,'⏳ Постепенно оно будет разослано всем. ...  ',getMainKeys());
}
if($data=="forwardToAll" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $sendInfo = json_decode(file_get_contents("settings/messagewizwiz.json"),true);
    $offset = $sendInfo['offset'];
    

    if($offset != -1 && !is_null($offset)) {
        $stmt = $connection->prepare("SELECT * FROM `users`");
        $stmt->execute();
        $usersCount = $stmt->get_result()->num_rows;
        $stmt->close();
        
        
        $leftMessages = $offset == 0 ? $usersCount - $offset : $usersCount - $offset;
        $offset = $offset == 0 ? $offset : $offset;
        if(json_decode($sendInfo['text'],true)['type'] == "forwardall"){
            sendMessage("❗️ Публичный релиз находится в очереди на выпуск, пожалуйста, наберитесь терпения...
            
             🔰 Количество пользователей: $usersCount
             ☑️ Импортировано: $offset
             📣 Осталось: $leftMessages
             ⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
            ");
        }else{
            sendMessage("❗️ Публичное сообщение находится в очереди на публикацию, наберитесь терпения...
            
             🔰 Количество пользователей: $usersCount
             ☑️ Отправлено: $offset
             📣 Осталось: $leftMessages
             ⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
            ");
        }
        exit;
    }
    
    delMessage();
    sendMessage($mainValues['forward_your_message'], $cancelKey);
    setUser($data);
}
if($userInfo['step'] == "forwardToAll" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $messageValue = json_encode(['type'=>'forwardall','message_id'=> $message_id, 'chat_id'=>$chat_id]);
    
    $sendInfo = json_decode(file_get_contents("settings/messagewizwiz.json"),true);
    $sendInfo['text'] = $messageValue;
    file_put_contents("settings/messagewizwiz.json",json_encode($sendInfo));

    setUser();
    sendMessage('⏳ Спасибо за ваше сообщение ...  ',$removeKeyboard);
    sendMessage("Скачать для всех?",json_encode(['inline_keyboard'=>[
    [['text'=>"Отправлять",'callback_data'=>"yesSend2All"],['text'=>"не отправил",'callback_data'=>"noDontSend2all"]]
    ]]));
}
if(preg_match('/selectServer(\d+)/',$data, $match) && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true)) ) {
    $sid = $match[1];
        
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent`=0 order by `id` asc");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert($mainValues['category_not_avilable']);
    }else{
        
        $keyboard = [];
        while ($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1");
            $stmt->bind_param("ii", $sid, $id);
            $stmt->execute();
            $rowcount = $stmt->get_result()->num_rows; 
            $stmt->close();
            if($rowcount>0) $keyboard[] = ['text' => "$name", 'callback_data' => "selectCategory{$id}_{$sid}"];
        }
        if(empty($keyboard)){
            alert($mainValues['category_not_avilable']);exit;
        }
        alert($mainValues['receive_categories']);

        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => 
        ($userInfo['temp'] == "agentBuy"?"agentOneBuy":($userInfo['temp'] == "agentMuchBuy"?"agentMuchBuy":"buySubscription"))];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id,$mainValues['buy_sub_select_category'], json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/selectCategory(\d+)_(\d+)/',$data,$match) && ($botState['sellState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match[1];
    $sid = $match[2];
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `price` != 0 and `catid`=? and `active`=1 order by `id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert($mainValues['no_plan_available']); 
    }else{
        alert($mainValues['receive_plans']);
        $keyboard = [];
        while($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $price = $file['price'];
            if($userInfo['is_agent'] == true && ($userInfo['temp'] == "agentBuy" || $userInfo['temp'] == "agentMuchBuy")){
                $discounts = json_decode($userInfo['discount_percent'],true);
                if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
                else $discount = $discounts['servers'][$sid]?? $discounts['normal'];
                
                $price -= floor($price * $discount / 100);
            }
            $price = ($price == 0) ? 'Бесплатно' : number_format($price).' تومان ';
            $keyboard[] = ['text' => "$name - $price", 'callback_data' => "selectPlan{$id}_{$call_id}"];
        }
        if($botState['plandelkhahState'] == "on"){
	        $keyboard[] = ['text' => $mainValues['buy_custom_plan'], 'callback_data' => "selectCustomPlan{$call_id}_{$sid}"];
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "selectServer$sid"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id,$mainValues['buy_sub_select_plan'], json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/selectCustomPlan(\d+)_(\d+)/',$data,$match) && ($botState['sellState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match[1];
    $sid = $match[2];
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1 order by `id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    alert($mainValues['receive_plans']);
    $keyboard = [];
    while($file = $respd->fetch_assoc()){
        $id = $file['id'];
        $name = preg_replace("/План\s(\d+)\sГб\s/","",$file['title']);
        $keyboard[] = ['text' => "$name", 'callback_data' => "selectCustomePlan{$id}_{$call_id}"];
    }
    $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "selectServer$sid"];
    $keyboard = array_chunk($keyboard,1);
    editText($message_id, $mainValues['select_one_plan_to_edit'], json_encode(['inline_keyboard'=>$keyboard]));

}
if(preg_match('/selectCustomePlan(\d+)_(\d+)/',$data, $match) && ($botState['sellState']=="on" ||$from_id ==$admin)){
	delMessage();
	$price = $botState['gbPrice'];
	if($userInfo['temp'] == "agentBuy" && $userInfo['is_agent'] == true){ 
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
        $stmt->execute();
        $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
        $stmt->close();

        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$match[1]]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
	}
	sendMessage(str_replace("VOLUME-PRICE", $price, $mainValues['customer_custome_plan_volume']),$cancelKey);
	setUser("selectCustomPlanGB" . $match[1] . "_" . $match[2]);
}
if(preg_match('/selectCustomPlanGB(\d+)_(\d+)/',$userInfo['step'], $match) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("😡Please only send the number");
        exit();
    }
    elseif($text <1){
        sendMessage("Please enter a number greater than 0");
        exit();
    }
    elseif(strpos($text,".")!==FALSE){
        sendMessage(" Decimal numbers are not allowed");
        exit();
    }
    elseif(substr($text, 0, 1) == '0'){
        sendMessage("❌ The entered number cannot start with 0!");
        exit();
    }
    
    $id = $match[1];
    $price = $botState['dayPrice'];
	if($userInfo['temp'] == "agentBuy" && $userInfo['is_agent'] == true){
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
        $stmt->execute();
        $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
        $stmt->close();

        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$match[1]]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
	}
    
	sendMessage(str_replace("DAY-PRICE", $price, $mainValues['customer_custome_plan_day']));
	setUser("selectCustomPlanDay" . $match[1] . "_" . $match[2] . "_" . $text);
}
if((preg_match('/selectCustomPlanDay(\d+)_(\d+)_(\d+)/',$userInfo['step'], $match)) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("😡 Please only send the number");
        exit();
    }
    elseif($text <1){
        sendMessage("Please enter a number greater than 0");
        exit();
    }
    elseif(strpos($text,".")!==FALSE){
        sendMessage("Decimal numbers are not allowed");
        exit();
    }
    elseif(substr($text, 0, 1) == '0'){
        sendMessage("❌ The entered number cannot start with 0!");
        exit();
    }

	sendMessage($mainValues['customer_custome_plan_name']);
	setUser("enterCustomPlanName" . $match[1] . "_" . $match[2] . "_" . $match[3] . "_" . $text);
}
if((preg_match('/^discountCustomPlanDay(\d+)/',$userInfo['step'], $match) || preg_match('/enterCustomPlanName(\d+)_(\d+)_(\d+)_(\d+)/',$userInfo['step'], $match)) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){
    if(preg_match('/^discountCustomPlanDay/', $userInfo['step'])){
        $rowId = $match[1];

        $time = time();
        $stmt = $connection->prepare("SELECT * FROM `discounts` WHERE (`expire_date` > $time OR `expire_date` = 0) AND (`expire_count` > 0 OR `expire_count` = -1) AND `hash_id` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ?");
        $stmt->bind_param("i", $rowId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $hash_id = $payInfo['hash_id'];
        $price = $payInfo['price'];
        $id = $payInfo['type'];
    	$volume = $payInfo['volume'];
        $days = $payInfo['day'];
        $stmt->close();
            
        if($list->num_rows>0){
            $discountInfo = $list->fetch_assoc();
            $amount = $discountInfo['amount'];
            $type = $discountInfo['type'];
            $count = $discountInfo['expire_count'];
            $usedBy = !is_null($discountInfo['used_by'])?json_decode($discountInfo['used_by'],true):array();
            
            $canUse = $discountInfo['can_use'];
            $userUsedCount = array_count_values($usedBy)[$from_id];
            if($canUse > $userUsedCount){
                $usedBy[] = $from_id;
                $encodeUsedBy = json_encode($usedBy);
                
                if ($count != -1) $query = "UPDATE `discounts` SET `expire_count` = `expire_count` - 1, `used_by` = ? WHERE `id` = ?";
                else $query = "UPDATE `discounts` SET `used_by` = ? WHERE `id` = ?";
            
                $stmt = $connection->prepare($query);
                $stmt->bind_param("si", $encodeUsedBy, $discountInfo['id']);
                $stmt->execute();
                $stmt->close();
                
                if($type == "percent"){
                    $discount = $price * $amount / 100;
                    $price -= $discount;
                    $discount = number_format($discount) . " تومان";
                }else{
                    $price -= $amount;
                    $discount = number_format($amount) . " تومان";
                }
                if($price < 0) $price = 0;
                
                $stmt = $connection->prepare("UPDATE `pays` SET `price` = ? WHERE `id` = ?");
                $stmt->bind_param("ii", $price, $rowId);
                $stmt->execute();
                $stmt->close();
                sendMessage(str_replace("AMOUNT", $discount, $mainValues['valid_discount_code']));
                $keys = json_encode(['inline_keyboard'=>[
                    [
                        ['text'=>"❤️", "callback_data"=>"wizwizch"]
                        ],
                    ]]);
            sendMessage(
                str_replace(['USERID', 'USERNAME', "NAME", "AMOUNT", "DISCOUNTCODE"], [$from_id, $username, $first_name, $discount, $text], $mainValues['used_discount_code'])
                ,$keys,null,$admin);
                }else sendMessage($mainValues['not_valid_discount_code']);
        }else sendMessage($mainValues['not_valid_discount_code']);
    }else{
        $id = $match[1];
    	$call_id = $match[2];
    	$volume = $match[3];
        $days = $match[4];
        if(preg_match('/[a-zA-z][0-9]/',$text)){} else{
            sendMessage($mainValues['incorrect_config_name']);
            exit();
        }
    }
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $respd['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $name = $catname." ".$respd['title'];
    $desc = $respd['descr'];
	$sid = $respd['server_id'];
	$keyboard = array();
    $token = base64_encode("{$from_id}.{$id}");

    if(!preg_match('/^discountCustomPlanDay/', $userInfo['step'])){
        $discountPrice = 0;
        $gbPrice = $botState['gbPrice'];
        $dayPrice = $botState['dayPrice'];
        
        if($userInfo['is_agent'] == true && $userInfo['temp'] == "agentBuy") {
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
            $stmt->bind_param("i", $match[1]);
            $stmt->execute();
            $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
            $stmt->close();
            
            $discounts = json_decode($userInfo['discount_percent'],true);
            if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
            else $discount = $discounts['servers'][$sid]?? $discounts['normal'];
            
            $gbPrice -= floor($gbPrice * $discount /100);
            $dayPrice -= floor($dayPrice * $discount / 100);
        }
        
        $price =  ($volume * $gbPrice) + ($days * $dayPrice);
        $hash_id = RandomString();
        $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'BUY_SUB' AND `state` = 'pending'");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $stmt->close();
        
        $time = time();
        $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                    VALUES (?, ?, ?, 'BUY_SUB', ?, ?, ?, ?, ?, 'pending')");
        $stmt->bind_param("ssiiiiii", $hash_id, $text, $from_id, $id, $volume, $days, $price, $time);
        $stmt->execute();
        $rowId = $stmt->insert_id;
        $stmt->close();
    }
    
    
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payCustomWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payCustomWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    if(!preg_match('/^discountCustomPlanDay/', $userInfo['step'])) $keyboard[] = [['text' => " 🎁 У вас есть код скидки? ",  'callback_data' => "haveDiscountCustom_" . $rowId]];
	$keyboard[] = [['text' => $buttonValues['cancel'], 'callback_data' => "mainMenu"]];
    $price = ($price == 0) ? 'Бесплатно' : number_format($price).' токен';
    sendMessage(str_replace(['VOLUME', 'DAYS', 'PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$volume, $days, $name, $price, $desc], $mainValues['buy_subscription_detail']),json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    setUser();
}
if(preg_match('/^haveDiscount(.+?)_(.*)/',$data,$match)){
    delMessage();
    sendMessage($mainValues['insert_discount_code'],$cancelKey);
    if($match[1] == "Custom") setUser('discountCustomPlanDay' . $match[2]);
    elseif($match[1] == "SelectPlan") setUser('discountSelectPlan' . $match[2]);
    elseif($match[1] == "Renew") setUser('discountRenew' . $match[2]);
}
if($data=="getTestAccount"){
    if($userInfo['freetrial'] != null && $from_id != $admin && $userInfo['isAdmin'] != true){
        alert("Вы уже использовали тестовый аккаунт");
        exit();
    }
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `price`=0");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    
    if($respd->num_rows > 0){
        alert($mainValues['receving_information']);
    	$keyboard = array();
        while ($row = $respd->fetch_assoc()){
            $id = $row['id'];
            $catInfo = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
            $catInfo->bind_param("i", $row['catid']);
            $catInfo->execute();
            $catname = $catInfo->get_result()->fetch_assoc()['title'];
            $catInfo->close();
            
            $name = $catname." ".$row['title'];
            $price =  $row['price'];
            $desc = $row['descr'];
        	$sid = $row['server_id'];

            $keyboard[] = [['text' => $name, 'callback_data' => "freeTrial$id"]];

        }
    	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
        editText($message_id,"Пожалуйста, выберите один из ключей ниже", json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    }else alert("Данный раздел временно неактивен");
}
if((preg_match('/^discountSelectPlan(\d+)_(\d+)_(\d+)/',$userInfo['step'],$match) || 
    preg_match('/selectPlan(\d+)_(\d+)/',$userInfo['step'], $match) || 
    preg_match('/enterAccountName(\d+)_(\d+)/',$userInfo['step'], $match) || 
    preg_match('/selectPlan(\d+)_(\d+)/',$data, $match)) && 
    ($botState['sellState']=="on" ||$from_id ==$admin) && 
    $text != $buttonValues['cancel']){
    if(preg_match('/^discountSelectPlan/', $userInfo['step'])){
        $rowId = $match[3];
        
        $time = time();
        $stmt = $connection->prepare("SELECT * FROM `discounts` WHERE (`expire_date` > $time OR `expire_date` = 0) AND (`expire_count` > 0 OR `expire_count` = -1) AND `hash_id` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ?");
        $stmt->bind_param("i", $rowId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $hash_id = $payInfo['hash_id'];
        $afterDiscount = $payInfo['price'];
        $stmt->close();
        
        if($list->num_rows>0){
            $discountInfo = $list->fetch_assoc();
            $amount = $discountInfo['amount'];
            $type = $discountInfo['type'];
            $count = $discountInfo['expire_count'];
            $canUse = $discountInfo['can_use'];
            $usedBy = !is_null($discountInfo['used_by'])?json_decode($discountInfo['used_by'],true):array();
            $userUsedCount = array_count_values($usedBy)[$from_id];
            if($canUse > $userUsedCount){
                $usedBy[] = $from_id;
                $encodeUsedBy = json_encode($usedBy);
                
                if ($count != -1) $query = "UPDATE `discounts` SET `expire_count` = `expire_count` - 1, `used_by` = ? WHERE `id` = ?";
                else $query = "UPDATE `discounts` SET `used_by` = ? WHERE `id` = ?";
    
                $stmt = $connection->prepare($query);
                $stmt->bind_param("si", $encodeUsedBy, $discountInfo['id']);
                $stmt->execute();
                $stmt->close();
                
                if($type == "percent"){
                    $discount = $afterDiscount * $amount / 100;
                    $afterDiscount -= $discount;
                    $discount = number_format($discount) . " токен";
                }else{
                    $afterDiscount -= $amount;
                    $discount = number_format($amount) . " токен";
                }
                if($afterDiscount < 0) $afterDiscount = 0;
                
                $stmt = $connection->prepare("UPDATE `pays` SET `price` = ? WHERE `id` = ?");
                $stmt->bind_param("ii", $afterDiscount, $rowId);
                $stmt->execute();
                $stmt->close();
                sendMessage(str_replace("AMOUNT", $discount, $mainValues['valid_discount_code']));
                $keys = json_encode(['inline_keyboard'=>[
                    [
                        ['text'=>"❤️", "callback_data"=>"wizwizch"]
                        ],
                    ]]);
                sendMessage(
                    str_replace(['USERID', 'USERNAME', "NAME", "AMOUNT", "DISCOUNTCODE"], [$from_id, $username, $first_name, $discount, $text], $mainValues['used_discount_code'])
                    ,$keys,null,$admin);
            }else sendMessage($mainValues['not_valid_discount_code']);
        }else sendMessage($mainValues['not_valid_discount_code']);
        setUser();
    }elseif(isset($data)) delMessage();


    if($botState['remark'] ==  "manual" && preg_match('/^selectPlan/',$data)){
        sendMessage($mainValues['customer_custome_plan_name'], $cancelKey);
        setUser('enterAccountName' . $match[1] . "_" . $match[2]);
        exit();
    }

    $remark = "";
    if(preg_match("/selectPlan(\d+)_(\d+)/",$userInfo['step'])){
        if($userInfo['temp'] == "agentMuchBuy"){
            if(is_numeric($text)){
                if($text > 0){
                    $accountCount = $text;
                    setUser();
                }else{sendMessage( $mainValues['send_positive_number']); exit(); }
            }else{ sendMessage($mainValues['send_only_number']); exit(); }
        }        
    }
    elseif(preg_match("/enterAccountName(\d+)_(\d+)/",$userInfo['step'])){
        if(preg_match('/[a-zA-z][0-9]/',$text)){
            $remark = $text;
            setUser();
        } else{
            sendMessage($mainValues['incorrect_config_name']);
            exit();
        }
    }
    else{
        if($userInfo['temp'] == "agentMuchBuy"){
            setUser($data);
            sendMessage($mainValues['enter_account_amount'], $cancelKey);
            exit();
        }
    }
    
    
    $id = $match[1];
	$call_id = $match[2];
    alert($mainValues['receving_information']);
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $respd['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $name = $catname." ".$respd['title'];
    $desc = $respd['descr'];
	$sid = $respd['server_id'];
	$keyboard = array();
    $price =  $respd['price'];
    if(isset($accountCount)) $price *= $accountCount;
    
    $agentBought = false;
    if($userInfo['is_agent'] == true && ($userInfo['temp'] == "agentBuy" || $userInfo['temp'] == "agentMuchBuy")){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
        else $discount = $discounts['servers'][$sid]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);

        $agentBought = true;
    }
    if($price == 0 or ($from_id == $admin)){
        $keyboard[] = [['text' => '📥 Бесплатная загрузка', 'callback_data' => "freeTrial$id"]];
        setUser($remark, 'temp');
    }else{
        $token = base64_encode("{$from_id}.{$id}");
        
        if(!preg_match('/^discountSelectPlan/', $userInfo['step'])){
            $hash_id = RandomString();
            $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'BUY_SUB' AND `state` = 'pending'");
            $stmt->bind_param("i", $from_id);
            $stmt->execute();
            $stmt->close();
            
            $time = time();
            if(isset($accountCount)){
                $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`, `agent_count`)
                                            VALUES (?, ?, 'BUY_SUB', ?, '0', '0', ?, ?, 'pending', ?, ?)");
                $stmt->bind_param("siiiiii", $hash_id, $from_id, $id, $price, $time, $agentBought, $accountCount);
            }else{
                $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`)
                                            VALUES (?, ?, ?, 'BUY_SUB', ?, '0', '0', ?, ?, 'pending', ?)");
                $stmt->bind_param("ssiiiii", $hash_id, $remark, $from_id, $id, $price, $time, $agentBought);
            }
            $stmt->execute();
            $rowId = $stmt->insert_id;
            $stmt->close();
        }else{
            $price = $afterDiscount;
        }
        
        if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payWithCartToCart$hash_id"]];
        if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
        if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
        if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
        if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
        if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payWithWallet$hash_id"]];
        if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];
        
        if(!preg_match('/^discountSelectPlan/', $userInfo['step'])) $keyboard[] = [['text' => " 🎁 У вас есть код скидки?",  'callback_data' => "haveDiscountSelectPlan_" . $match[1] . "_" . $match[2] . "_" . $rowId]];

    }
	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "selectCategory{$call_id}_{$sid}"]];
    $priceC = ($price == 0) ? 'Бесплатно' : number_format($price).' токен ';
    if(isset($accountCount)){
        $eachPrice = number_format($price / $accountCount) . " токен";
        $msg = str_replace(['ACCOUNT-COUNT', 'TOTAL-PRICE', 'PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$accountCount, $priceC, $name, $eachPrice, $desc], $mainValues['buy_much_subscription_detail']);
    }
    else $msg = str_replace(['PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$name, $priceC, $desc], $mainValues['buy_subscription_detail']);
    sendMessage($msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML");
}
if(preg_match('/payCustomWithWallet(.*)/',$data, $match)){
    setUser();
    
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $uid = $from_id;
    $fid = $payInfo['plan_id']; 
    $volume = $payInfo['volume'];
    $days = $payInfo['day'];
    
    $acctxt = '';
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $protocol = $file_detail['protocol'];
    $price = $payInfo['price'];

    if($userInfo['wallet'] < $price){
        alert("Баланс вашего счета низкий");
        exit();
    }
    
    
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];


    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }

    $uniqid = generateRandomString(42,$protocol); 

    $savedinfo = file_get_contents('settings/temp.txt');
    $savedinfo = explode('-',$savedinfo);
    $port = $savedinfo[0] + 1;
    $last_num = $savedinfo[1] + 1;

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $srv_remark = $serverInfo['remark'];
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $portType = $stmt->get_result()->fetch_assoc()['port_type'];
    $stmt->close();

    // $rnd = rand(1111,99999);
    // $remark = "{$srv_remark}-{$from_id}-{$rnd}";
    $remark = $payInfo['description']; 
    
    if($portType == "auto"){
        file_put_contents('settings/temp.txt',$port.'-'.$last_num);
    }else{
        $port = rand(1111,65000);
    }
    
    if($inbound_id == 0){    
        $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
        if(!$response->success){
            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
        } 
    }else {
        $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
        if(!$response->success){
            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
        } 
    }
    
    if(is_null($response)){
        alert('❌ | 🥺 Голам, соединение с сервером не установлено, сообщите об этом администратору ...');
        exit;
    }
	if($response == "inbound not Found"){
        alert("❌ | 🥺 Линия (inbound) с идентификатором $inbound_id не существует на этом сервере, сообщите менеджеру ...");
		exit;
	}
	if(!$response->success){
        alert('❌ | 😮 Ого, произошла ошибка, пожалуйста, срочно сообщите менеджеру....');
        sendMessage("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
        exit;
    }
    alert($mainValues['sending_config_to_user']);
    
    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
    $stmt->bind_param("ii", $price, $uid);
    $stmt->execute();
    include 'phpqrcode/qrlib.php';
    $token = RandomString(30);
    $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";

    $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
    delMessage();
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);
    foreach($vraylink as $vray_link){
        $acc_text = "😍 Ваш новый заказ
📡 Протокол: $protocol
🔮 Имя сервиса: $remark
🔋 Объем траффика: $volume Гб.
⏰ Период действия: $days дней
" . ($botState['configLinkState'] != "off"?"
💝 config : <code>$vray_link</code>":"");
if($botState['subLinkState'] == "on") $acc_text .= "

🔋 Volume web: <code> $botUrl"."search.php?id=".$uniqid."</code>


🌐 subscription : <code>$subLink</code>"; 
    
        $file = RandomString() .".png";
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 0;
        
        QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
    	addBorderImage($file);
    	
        $backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

    	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
        unlink($file);
    }

    
    if($userInfo['refered_by'] != null){
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
        $stmt->close();
        $inviterId = $userInfo['refered_by'];
        
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $inviteAmount, $inviterId);
        $stmt->execute();
        $stmt->close();
         
        sendMessage("Поздравляем, одно из ваших подмножеств совершило покупку. " . number_format($inviteAmount) . " Вы получили вознаграждение в размере руб.",null,null,$inviterId);
    }
    $vray_link = json_encode($vraylink);

	$stmt = $connection->prepare("INSERT INTO `orders_list` 
	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`)
	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?);");
    $stmt->bind_param("ssiiisssisiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $price, $date, $rahgozar);
    $stmt->execute();
    $order = $stmt->get_result(); 
    $stmt->close();
    
    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - 1 WHERE id=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $stmt->close();
    }

    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"Я хочу купить новый ❤️",'callback_data'=>"wizwizch"]
        ],
        ]]);
    $msg = str_replace(['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                ['کیف پول', $from_id, $username, $first_name, $price, $remark,$volume, $days], $mainValues['buy_custom_account_request']);
    sendMessage($msg,$keys,"html", $admin);
}
if(preg_match('/^showQr(Sub|Config)(\d+)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `id`=?");
    $stmt->bind_param("ii", $from_id, $match[2]);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    include 'phpqrcode/qrlib.php';
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);
    if($match[1] == "Sub"){
        $subLink = $botUrl . "settings/subLink.php?token=" . $order['token'];
        $file = RandomString() .".png";
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 0;
        
        QRcode::png($subLink, $file, $ecc, $pixel_Size, $frame_Size);
    	addBorderImage($file);
    	
    	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

    	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
        unlink($file);
    }
    elseif($match[1] == "Config"){

        
        
        $vraylink = json_decode($order['link'],true);
        define('IMAGE_WIDTH',540);
        define('IMAGE_HEIGHT',540);
        foreach($vraylink as $vray_link){
            $file = RandomString() .".png";
            $ecc = 'L';
            $pixel_Size = 11;
            $frame_Size = 0;
            
            QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
        	addBorderImage($file);
            	
        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
            $qrImage = imagecreatefrompng($file);
            
            $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
            imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
            imagepng($backgroundImage, $file);
            imagedestroy($backgroundImage);
            imagedestroy($qrImage);
            
        	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
            unlink($file);
        }
    }
}
if(preg_match('/payCustomWithCartToCart(.*)/',$data, $match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $fid = $payInfo['plan_id'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];


    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }else{
        if($acount != 0 && $acount <= 0){
            sendMessage(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
            exit();
        }
    }
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
    else $paymentKeys = array();
    $stmt->close();

    
    setUser($data);
    delMessage();
    sendMessage(str_replace(["ACCOUNT-NUMBER", "HOLDER-NAME"],[$paymentKeys['bankAccount'],$paymentKeys['holderName']], $mainValues['buy_account_cart_to_cart']),$cancelKey, "HTML");
    exit;
}
if(preg_match('/payCustomWithCartToCart(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']){
    if(isset($update->message->photo)){
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $stmt->execute();
        
        $fid = $payInfo['plan_id'];
        $volume = $payInfo['volume'];
        $days = $payInfo['day'];
        
        setUser();
        $uid = $userInfo['userid'];
        $name = $userInfo['name'];
        $username = $userInfo['username'];
    
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $res['catid']);
        $stmt->execute();
        $catname = $stmt->get_result()->fetch_assoc()['title'];
        $stmt->close();
        $filename = $catname." ".$res['title']; 
        $fileprice = $payInfo['price'];
        $remark = $payInfo['description'];
        
        sendMessage($mainValues['order_buy_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
        $msg = str_replace(['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                            ["کارت به کارت", $from_id, $username, $first_name, $fileprice, $remark,$volume, $days], $mainValues['buy_custom_account_request']);
        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "accCustom" . $match[1]],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decline$uid"]
                ]
            ]
        ]);
        sendPhoto($fileid, $msg,$keyboard, "HTML", $admin);
    }else{
        sendMessage($mainValues['please_send_only_image']);
    }
}
if(preg_match('/accCustom(.*)/',$data, $match) and $text != $buttonValues['cancel']){
    setUser();

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $fid = $payInfo['plan_id'];
    $volume = $payInfo['volume'];
    $days = $payInfo['day'];
    $uid = $payInfo['user_id'];

    $acctxt = '';
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $protocol = $file_detail['protocol'];
    $price = $payInfo['price'];
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];
    $rahgozar = $file_detail['rahgozar'];

    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }

    $uniqid = generateRandomString(42,$protocol); 

    $savedinfo = file_get_contents('settings/temp.txt');
    $savedinfo = explode('-',$savedinfo);
    $port = $savedinfo[0] + 1;
    $last_num = $savedinfo[1] + 1;

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $srv_remark = $serverInfo['remark'];
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $portType = $stmt->get_result()->fetch_assoc()['port_type'];
    $stmt->close();

    // $rnd = rand(1111,99999);
    // $remark = "{$srv_remark}-{$uid}-{$rnd}";
    $remark = $payInfo['description'];
    
    if($portType == "auto"){
        file_put_contents('settings/temp.txt',$port.'-'.$last_num);
    }else{
        $port = rand(1111,65000);
    }
    
    if($inbound_id == 0){    
        $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
        if(!$response->success){
            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
        } 
    }else {
        $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
        if(!$response->success){
            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
        } 
    }
    
    if(is_null($response)){
        alert('❌ | 🥺 Голам, соединение с сервером не установлено, сообщите об этом администратору...');
        exit;
    }
	if($response == "inbound not Found"){
        alert("❌ | 🥺 Линия (inbound) с идентификатором $inbound_id не существует на этом сервере, сообщите менеджеру ...");
		exit;
	}
	if(!$response->success){
        alert('❌ | 😮 Ого, произошла ошибка, пожалуйста, срочно сообщите менеджеру. ...');
        sendMessage("Ошибка сервера {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
        exit;
    }
    alert($mainValues['sending_config_to_user']);
    
    include 'phpqrcode/qrlib.php';
    $token = RandomString(30);
    $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";

    $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id);
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);

    foreach($vraylink as $vray_link){
        $acc_text = "😍 Ваш новый заказ
📡 Протокол: $protocol
🔮 Имя сервиса: $remark
🔋 Объем услуги: $volume Гб.
⏰ Продолжительность услуги: $days
" . ($botState['configLinkState'] != "off"?"
💝 config : <code>$vray_link</code>":"");
if($botState['subLinkState'] == "on") $acc_text .= "

🔋 Volume web: <code> $botUrl"."search.php?id=".$uniqid."</code>

\n🌐 subscription : <code>$subLink</code>";
    
        $file = RandomString() .".png";
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 0;
    
        QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
    	addBorderImage($file);
    	
    	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

    	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
        unlink($file);
    }
    sendMessage('✅ Я отправил ему конфигурацию', getMainKeys());
    
    $vray_link= json_encode($vraylink);
	$stmt = $connection->prepare("INSERT INTO `orders_list` 
	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`)
	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?);");
    $stmt->bind_param("ssiiisssisiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $price, $date, $rahgozar);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();


    unset($markup[count($markup)-1]);
    $markup[] = [['text'=>"✅",'callback_data'=>"wizwizch"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);


    editKeys($keys);
    
    $filename = $file_detail['title'];
    $fileprice = number_format($file_detail['price']);
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $user_detail= $stmt->get_result()->fetch_assoc();
    $stmt->close();


    if($user_detail['refered_by'] != null){
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
        $stmt->close();
        $inviterId = $user_detail['refered_by'];
        
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $inviteAmount, $inviterId);
        $stmt->execute();
        $stmt->close();
         
        sendMessage("Поздравляем, одно из ваших подмножеств совершило покупку. " . number_format($inviteAmount) . " Вы получили вознаграждение в размере руб.",null,null,$inviterId);
    }

    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - 1 WHERE id=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $stmt->close();
    }

    $uname = $user_detail['name'];
    $user_name = $user_detail['username'];
    
    if($admin != $from_id){ 
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"به به 🛍",'callback_data'=>"wizwizch"]
            ],
            ]]);
        $msg = str_replace(['USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'FILENAME'],
            [$uid, $user_name, $uname, $price, $remark,$filename], $mainValues['invite_buy_new_account']);
        sendMessage($msg,null,null,$admin);
    }
    
}
if(preg_match('/payWithWallet(.*)/',$data, $match)){
    setUser();

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    
    $uid = $from_id;
    $fid = $payInfo['plan_id'];
    $acctxt = '';
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = $file_detail['days'];
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $volume = $file_detail['volume'];
    $protocol = $file_detail['protocol'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $price = $payInfo['price'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    if($userInfo['wallet'] < $price){
        alert("Баланс вашего счета низкий");
        exit();
    }

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    
    
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];


    if($payInfo['type'] == "RENEW_SCONFIG"){
        $configInfo = json_decode($payInfo['description'],true);
        $uuid = $configInfo['uuid'];
        $remark = $configInfo['remark'];
        
        $inbound_id = $payInfo['volume']; 
        
        if($inbound_id > 0)
            $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
        else
            $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
        
    	if(is_null($response)){
    		alert('🔻Техническая проблема с подключением к серверу. Пожалуйста, сообщите руководству',true);
    		exit;
    	}
    	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    	$stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
    	$stmt->execute();
    	$stmt->close();
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]
            ],
            ]]);
        editText($message_id,"✅ Услуга $remark успешно продлена.",$keys);
    }else{
        $accountCount = $payInfo['agent_count']!=0?$payInfo['agent_count']:1;
        
        if($acount == 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit;
            }
        }        
    
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $srv_remark = $serverInfo['remark'];
        $serverTitle = $serverInfo['title'];
        $stmt->close();
    
        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $portType = $stmt->get_result()->fetch_assoc()['port_type'];
        $stmt->close();

        include 'phpqrcode/qrlib.php';
        $msg = $message_id;

        $agent_bought = false;
	    $eachPrice = $price / $accountCount;
        if($userInfo['is_agent'] == true && ($userInfo['temp'] == "agentBuy" || $userInfo['temp'] == "agentMuchBuy")) {$agent_bought = true; setUser('', 'temp');}

        alert($mainValues['sending_config_to_user']);
        define('IMAGE_WIDTH',540);
        define('IMAGE_HEIGHT',540);
        for($i = 1; $i <= $accountCount; $i++){
            $uniqid = generateRandomString(42,$protocol); 
        
            $savedinfo = file_get_contents('settings/temp.txt');
            $savedinfo = explode('-',$savedinfo);
            $port = $savedinfo[0] + 1;
            $last_num = $savedinfo[1] + 1;
        
        
            if($botState['remark'] == "digits"){
                $rnd = rand(10000,99999);
                $remark = "{$srv_remark}-{$rnd}";
            }
            elseif($botState['remark'] == "manual"){
                $remark = $payInfo['description'];
            }
            else{
                $rnd = rand(1111,99999);
                $remark = "{$srv_remark}-{$from_id}-{$rnd}";
            }
        
            if($portType == "auto"){
                file_put_contents('settings/temp.txt',$port.'-'.$last_num);
            }else{
                $port = rand(1111,65000);
            }
        
            if($inbound_id == 0){    
                $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
                if(!$response->success){
                    $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
                } 
            }else {
                $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
                if(!$response->success){
                    $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
                } 
            }
        
            if(is_null($response)){
                sendMessage('❌ | 🥺 Голам, соединение с сервером не установлено, сообщите об этом администратору...');
                exit;
            }
        	if($response == "inbound not Found"){
                sendMessage("❌ | 🥺 Линия (inbound) с идентификатором $inbound_id не существует на этом сервере, сообщите менеджеру...");
        		exit;
        	}
        	if(!$response->success){
                sendMessage('❌ | 😮 Ого, произошла ошибка, пожалуйста, срочно сообщите менеджеру. ...');
                sendMessage("Ошибка сервера {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
                exit;
            }
        
        
            $token = RandomString(30);
            $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";

            $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
            foreach($vraylink as $vray_link){
                $acc_text = "😍 Ваш новый заказ
📡 Протокол: $protocol
🔮 Имя сервиса: $remark
🔋 Объем услуги: $volume Гб.
⏰ Продолжительность услуги: $days
" . ($botState['configLinkState'] != "off"?"
💝 config : <code>$vray_link</code>":"");
if($botState['subLinkState'] == "on") $acc_text .= "

🔋 Volume web: <code> $botUrl"."search.php?id=".$uniqid."</code>

\n🌐 subscription : <code>$subLink</code>";
            
                $file = RandomString() .".png";
                $ecc = 'L';
                $pixel_Size = 11;
                $frame_Size = 0;
                
                QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
            	addBorderImage($file);
            	
	        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
                $qrImage = imagecreatefrompng($file);
                
                $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
                imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
                imagepng($backgroundImage, $file);
                imagedestroy($backgroundImage);
                imagedestroy($qrImage);

            	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
                unlink($file);
            }
    
            $vray_link= json_encode($vraylink);
            
        	$stmt = $connection->prepare("INSERT INTO `orders_list` 
        	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
        	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
            $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $date, $rahgozar, $agent_bought);
            $stmt->execute();
            $order = $stmt->get_result(); 
            $stmt->close();
        }
    
        delMessage($msg);
        if($userInfo['refered_by'] != null){
            $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
            $stmt->execute();
            $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
            $stmt->close();
            $inviterId = $userInfo['refered_by'];
            
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $inviteAmount, $inviterId);
            $stmt->execute();
            $stmt->close();
             
            sendMessage("Поздравляем, одно из ваших подмножеств совершило покупку. " . number_format($inviteAmount) . " Вы получили вознаграждение в размере руб.",null,null,$inviterId);
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - ? WHERE `id`=?");
            $stmt->bind_param("ii", $accountCount, $server_id);
            $stmt->execute();
            $stmt->close();
        }else{
            $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE id=?");
            $stmt->bind_param("ii", $accountCount, $fid);
            $stmt->execute();
            $stmt->close();
        }
    }
    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
    $stmt->bind_param("ii", $price, $uid);
    $stmt->execute();
    $stmt->close();
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"Я хочу купить новый ❤️",'callback_data'=>"wizwizch"]
        ],
        ]]);
    if($payInfo['type'] == "RENEW_SCONFIG"){$msg = str_replace(['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                ['кошелек', $from_id, $username, $first_name, $price, $remark,$volume, $days], $mainValues['renew_account_request_message']);}
    else{$msg = str_replace(['SERVERNAME', 'TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                [$serverTitle, 'кошелек', $from_id, $username, $first_name, $price, $remark,$volume, $days], $mainValues['buy_new_account_request']);}

    sendMessage($msg,$keys,"html", $admin);
}
if(preg_match('/payWithCartToCart(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $fid = $payInfo['plan_id'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];

    if($payInfo['type'] != "RENEW_SCONFIG"){
        if($acount == 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit;
            }
        }else{
            if($acount <= 0){
                alert(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
                exit();
            }
        }
    }
    
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
    else $paymentKeys = array();
    $stmt->close();

    
    setUser($data);
    delMessage();
    sendMessage(str_replace(["ACCOUNT-NUMBER", "HOLDER-NAME"],[$paymentKeys['bankAccount'],$paymentKeys['holderName']], $mainValues['buy_account_cart_to_cart']),$cancelKey, "HTML");
    exit;
}
if(preg_match('/payWithCartToCart(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']){
    if(isset($update->message->photo)){
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $stmt->close();
    
        
        $fid = $payInfo['plan_id'];
        setUser();
        $uid = $userInfo['userid'];
        $name = $userInfo['name'];
        $username = $userInfo['username'];
    
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $days = $res['days'];
        $volume = $res['volume'];
        
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $res['server_id']);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $serverTitle = $serverInfo['title'];
    
        if($payInfo['type'] == "RENEW_SCONFIG"){
            $configInfo = json_decode($payInfo['description'],true);
            $filename = $configInfo['remark'];
        }else{
            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
            $stmt->bind_param("i", $res['catid']);
            $stmt->execute();
            $catname = $stmt->get_result()->fetch_assoc()['title'];
            $stmt->close();
            $filename = $catname." ".$res['title']; 
        }
        $fileprice = $payInfo['price'];
    
        sendMessage($mainValues['order_buy_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
        if($payInfo['agent_count'] != 0) $msg = str_replace(['ACCOUNT-COUNT', 'TYPE', 'USER-ID', "USERNAME", "NAME", "PRICE", "REMARK"],[$payInfo['agent_count'], 'کارت به کارت', $from_id, $username, $name, $fileprice, $filename], $mainValues['buy_new_much_account_request']);
        else $msg = str_replace(['SERVERNAME', 'TYPE', 'USER-ID', "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],[$serverTitle, 'کارت به کارت', $from_id, $username, $name, $fileprice, $filename, $volume, $days], $mainValues['buy_new_account_request']);

        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "accept" . $match[1] ],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decline$uid"]
                ]
            ]
        ]);
        setUser('', 'temp');
        $res = sendPhoto($fileid, $msg,$keyboard, "HTML", $admin);
    }else{
        sendMessage($mainValues['please_send_only_image']);
    }
}
if($data=="availableServers"){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `acount` != 0 AND `inbound_id` != 0");
    $stmt->execute();
    $serversList = $stmt->get_result();
    $stmt->close();

    $keys = array();
    $keys[] = [
        ['text'=>"Оставшееся число",'callback_data'=>"wizwizch"],
        ['text'=>"план",'callback_data'=>"wizwizch"],
        ['text'=>'сервер','callback_data'=>"wizwizch"]
        ];
    while($file_detail = $serversList->fetch_assoc()){
        $days = $file_detail['days'];
        $title = $file_detail['title'];
        $server_id = $file_detail['server_id'];
        $acount = $file_detail['acount'];
        $inbound_id = $file_detail['inbound_id'];
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $name = $stmt->get_result();
        $stmt->close();

        if($name->num_rows>0){
            $name = $name->fetch_assoc()['title'];
            
            $keys[] = [
                ['text'=>$acount . " اکانت",'callback_data'=>"wizwizch"],
                ['text'=>$title??" ",'callback_data'=>"wizwizch"],
                ['text'=>$name??" ",'callback_data'=>"wizwizch"]
                ];
        }
    }
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]];
    $keys = json_encode(['inline_keyboard'=>$keys]);
    editText($message_id, "🟢 | Инвентарь плана подписки:", $keys);
}
if($data=="availableServers2"){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `inbound_id` = 0");
    $stmt->execute();
    $serversList = $stmt->get_result();
    $stmt->close();

    $keys = array();
    $keys[] = [
        ['text'=>"Оставшееся число",'callback_data'=>"wizwizch"],
        ['text'=>'сервер','callback_data'=>"wizwizch"]
        ];
    while($file_detail2 = $serversList->fetch_assoc()){
        $days2 = $file_detail2['days'];
        $title2 = $file_detail2['title'];
        $server_id2 = $file_detail2['server_id'];
        $inbound_id2 = $file_detail2['inbound_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
        $stmt->bind_param("i", $server_id2);
        $stmt->execute();
        $name = $stmt->get_result();
        $stmt->close();

        if($name->num_rows>0){
            $sInfo = $name->fetch_assoc();
            $name = $sInfo['title'];
            $acount2 = $sInfo['ucount'];
            
            $keys[] = [
                ['text'=>$acount2 . " счет",'callback_data'=>"wizwizch"],
                ['text'=>$title2??" ",'callback_data'=>"wizwizch"],
                ];
        }
    }
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]];
    $keys = json_encode(['inline_keyboard'=>$keys]);
    editText($message_id, "🟢 | Эксклюзивный плановый инвентарь:", $keys);
}
if($data=="agencySettings" && $userInfo['is_agent'] == 1){
    editText($message_id, $mainValues['agent_setting_message'] ,getAgentKeys());
}
if($data=="requestAgency"){
    if($userInfo['is_agent'] == 2){
        alert($mainValues['agency_request_already_sent']);
    }elseif($userInfo['is_agent'] == 0){
        $msg = str_replace(["USERNAME", "NAME", "USERID"], [$username, $first_name, $from_id], $mainValues['request_agency_message']);
        sendMessage($msg, json_encode(['inline_keyboard'=>[
            [
                ['text' => $buttonValues['approve'], 'callback_data' => "agencyApprove" . $from_id ],
                ['text' => $buttonValues['decline'], 'callback_data' => "agencyDecline" . $from_id]
            ]
            ]]), null, $admin);
        setUser(2, 'is_agent');
        alert($mainValues['agency_request_sent']);
    }elseif($userInfo['is_agent'] == -1) alert($mainValues['agency_request_declined']);
    elseif($userInfo['is_agent'] == 1) editText($message_id,"Пожалуйста, выберите один из ключей ниже",getMainKeys());
}
if(preg_match('/^agencyDecline(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editKeys(json_encode(['inline_keyboard'=>[
        [['text'=>$buttonValues['declined'],'callback_data'=>"wizwizch"]]
        ]]));
    sendMessage($mainValues['agency_request_declined'], null,null,$match[1]);
    setUser(-1, 'is_agent', $match[1]);
}
if(preg_match('/^agencyApprove(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data . "_" . $message_id);
    sendMessage($mainValues['send_agent_discount_percent'], $cancelKey);
}
if(preg_match('/^agencyApprove(\d+)_(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        editKeys(json_encode(['inline_keyboard'=>[
            [['text'=>$buttonValues['approved'],'callback_data'=>"wizwizch"]]
            ]]), $match[2]);
        sendMessage($mainValues['saved_successfuly']);
        setUser();
        $discount = json_encode(['normal'=>$text]);
        $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 1, `discount_percent` = ?, `agent_date` = ? WHERE `userid` = ?");
        $stmt->bind_param("sii", $discount, $time, $match[1]);
        $stmt->execute();
        $stmt->close();
        sendMessage($mainValues['agency_request_approved'], null,null,$match[1]);
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/accept(.*)/',$data, $match) and $text != $buttonValues['cancel']){
    setUser();
    
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    $uid = $payInfo['user_id'];
    $fid = $payInfo['plan_id'];
    $acctxt = '';
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = $file_detail['days'];
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $volume = $file_detail['volume'];
    $protocol = $file_detail['protocol'];
    $price = $payInfo['price'];
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];

    
    if($payInfo['type'] == "RENEW_SCONFIG"){
        $configInfo = json_decode($payInfo['description'],true);
        $uuid = $configInfo['uuid'];
        $remark = $configInfo['remark'];
        
        $inbound_id = $payInfo['volume']; 
        
        if($inbound_id > 0)
            $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
        else
            $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
        
    	if(is_null($response)){
    		alert('🔻Техническая проблема с подключением к серверу. Пожалуйста, сообщите руководству',true);
    		exit;
    	}
    	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    	$stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
    	$stmt->execute();
    	$stmt->close();
        sendMessage(str_replace(["REMARK", "VOLUME", "DAYS"],[$remark, $volume, $days], $mainValues['renewed_config_to_user']), getMainKeys(),null,null);
        sendMessage("✅ Услуга $remark успешно продлена.",null,null,$uid);
    }else{
        $accountCount = $payInfo['agent_count'] != 0? $payInfo['agent_count']:1;
        $eachPrice = $price / $accountCount;
        
        if($acount == 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0){
                alert($mainValues['out_of_server_capacity']);
                exit;
            }
        }
        
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $srv_remark = $serverInfo['remark'];
        $stmt->close();
    
        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $portType = $stmt->get_result()->fetch_assoc()['port_type'];
        $stmt->close();
    
    
        alert($mainValues['sending_config_to_user']);
        include 'phpqrcode/qrlib.php';
        define('IMAGE_WIDTH',540);
        define('IMAGE_HEIGHT',540);
        for($i = 1; $i <= $accountCount; $i++){
            $uniqid = generateRandomString(42,$protocol); 
        
            $savedinfo = file_get_contents('settings/temp.txt');
            $savedinfo = explode('-',$savedinfo);
            $port = $savedinfo[0] + 1;
            $last_num = $savedinfo[1] + 1;
    
    
            if($botState['remark'] == "digits"){
                $rnd = rand(10000,99999);
                $remark = "{$srv_remark}-{$rnd}";
            }
            elseif($botState['remark'] == "manual"){
                $remark = $payInfo['description'];
            }
            else{
                $rnd = rand(1111,99999);
                $remark = "{$srv_remark}-{$uid}-{$rnd}";
            }
        
            if($portType == "auto"){
                file_put_contents('settings/temp.txt',$port.'-'.$last_num);
            }else{
                $port = rand(1111,65000);
            }
        
            if($inbound_id == 0){    
                $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
                if(!$response->success){
                    $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
                } 
            }else {
                $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
                if(!$response->success){
                    $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
                } 
            }
            if(is_null($response)){
                sendMessage('❌ | 🥺 Голам, соединение с сервером не установлено, сообщите об этом администратору...');
                exit;
            }
        	if($response == "inbound not Found"){
                sendMessage("❌ | 🥺 Линия (inbound) с идентификатором $inbound_id не существует на этом сервере, сообщите менеджеру ...");
        		exit;
        	}
        	if(!$response->success){
                sendMessage('❌ | 😮 Ого, произошла ошибка, пожалуйста, срочно сообщите менеджеру. ...');
                sendMessage("Ошибка сервера {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
                exit;
            }
                
            $token = RandomString(30);
            $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";
    
            $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
            foreach($vraylink as $vray_link){
                $acc_text = "😍 Ваш новый заказ
📡 Протокол: $protocol
🔮 Имя сервиса: $remark
🔋 Объем услуги: $volume Гб.
⏰ Продолжительность услуги: $days
" . ($botState['configLinkState'] != "off"?"
💝 config : <code>$vray_link</code>":"");
if($botState['subLinkState'] == "on") $acc_text .= "

🔋 Volume web: <code> $botUrl"."search.php?id=".$uniqid."</code>

\n🌐 subscription : <code>$subLink</code>";
            
                $file = RandomString() .".png";
                $ecc = 'L';
                $pixel_Size = 11;
                $frame_Size = 0;
            
                QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
            	addBorderImage($file);
            	
            	
	        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
                $qrImage = imagecreatefrompng($file);
                
                $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
                imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
                imagepng($backgroundImage, $file);
                imagedestroy($backgroundImage);
                imagedestroy($qrImage);

            	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
                unlink($file);
            }
            $agent_bought = $payInfo['agent_bought'];
    
            $vray_link = json_encode($vraylink);
        	$stmt = $connection->prepare("INSERT INTO `orders_list` 
        	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
        	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
            $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $date, $rahgozar, $agent_bought);
            $stmt->execute();
            $order = $stmt->get_result();
            $stmt->close();
        }
        sendMessage(str_replace(["REMARK", "VOLUME", "DAYS"],[$remark, $volume, $days], $mainValues['sent_config_to_user']), getMainKeys());
        if($inbound_id == 0) {
            $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - ? WHERE `id`=?");
            $stmt->bind_param("ii", $accountCount, $server_id);
            $stmt->execute();
            $stmt->close();
        }else{
            $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE id=?");
            $stmt->bind_param("ii", $accountCount, $fid);
            $stmt->execute();
            $stmt->close();
        }

    }

    unset($markup[count($markup)-1]);
    $markup[] = [['text'=>"✅",'callback_data'=>"wizwizch"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);

    editKeys($keys);
    if($payInfo['type'] != "RENEW_SCONFIG"){
        $filename = $file_detail['title'];
        $fileprice = number_format($file_detail['price']);
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $user_detail= $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        if($user_detail['refered_by'] != null){
            $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
            $stmt->execute();
            $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
            $stmt->close();
            $inviterId = $user_detail['refered_by'];
            
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $inviteAmount, $inviterId);
            $stmt->execute();
            $stmt->close();
             
            sendMessage("Поздравляем, одно из ваших подмножеств совершило покупку." . number_format($inviteAmount) . " Вы получили вознаграждение в размере руб.",null,null,$inviterId);
        }
    
    
        $uname = $user_detail['name'];
        $user_name = $user_detail['username'];
        
        if($admin != $from_id){
            $keys = json_encode(['inline_keyboard'=>[
                [
                    ['text'=>"чтобы к 🛍",'callback_data'=>"wizwizch"]
                ],
                ]]);
                
        $msg = str_replace(['USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'FILENAME'],
                    [$uid, $user_name, $uname, $price, $remark,$filename], $mainValues['invite_buy_new_account']);
            
            sendMessage($msg,null,null,$admin);
        }
    }
}
if(preg_match('/decline/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data . "_" . $message_id);
    sendMessage('В чем причина не подтверждения? (Спроси его) 😔 ',$cancelKey);
}
if(preg_match('/decline(\d+)_(\d+)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    setUser();
    $uid = $match[1];
    editKeys(
        json_encode(['inline_keyboard'=>[
	    [['text'=>"Отменено ❌",'callback_data'=>"wizwizch"]]
	    ]]) ,$match[2]);

    sendMessage('Я отправил ему твое сообщение ... 🤝',$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
    sendMessage($text, null, null, $uid);
}
if($data=="supportSection"){
    editText($message_id,"Добро пожаловать в раздел поддержки🛂\nПожалуйста, выберите одну из кнопок ниже.",
        json_encode(['inline_keyboard'=>[
        [['text'=>"✉️ Регистрация тикетов",'callback_data'=>"usersNewTicket"]],
        [['text'=>"Открытые тикеты 📨",'callback_data'=>"usersOpenTickets"],['text'=>"📮 Список тикетов", 'callback_data'=>"userAllTickets"]],
        [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]]
        ]]));
}
if($data== "usersNewTicket"){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    $keys = array();
    $temp = array();
    if($ticketCategory->num_rows >0){
        while($row = $ticketCategory->fetch_assoc()){
            $ticketName = $row['value'];
            $temp[] = ['text'=>$ticketName,'callback_data'=>"supportCat$ticketName"];
            
            if(count($temp) == 2){
                array_push($keys,$temp);
                $temp = null;
            }
        }
        
        if($temp != null){
            if(count($temp)>0){
                array_push($keys,$temp);
                $temp = null;
            }
        }
        $temp[] = ['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"];
        array_push($keys,$temp);
        editText($message_id,"💠Пожалуйста, выберите желаемый блок!",json_encode(['inline_keyboard'=>$keys]));
    }else{
        alert("Эй, извини, меня сейчас здесь нет");
    }
}
if($data == 'dayPlanSettings' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       editText($message_id, 'Список расписаний пуст',json_encode([
                'inline_keyboard' => [
                    [['text' => "Добавить новое расписание", 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"backplan"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"Удалить",'callback_data'=>"wizwizch"],['text'=>"Цена",'callback_data'=>"wizwizch"],['text'=>"количество дней",'callback_data'=>"wizwizch"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " токен";
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => "Добавить новое расписание", 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "backplan"]];
    $msg = ' 📍 Нажмите на нее, чтобы увидеть подробности расписания👇';
    
    editText($message_id,$msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    exit;
}
if($data=='addNewDayPlan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("Введите количество дней и их цену ниже :
10-30000

Первое значение — продолжительность (10) дней.
Вторая цена (30 000) токенов.
 ",$cancelKey);exit;
}
if($userInfo['step'] == "addNewDayPlan" and $text != $buttonValues['cancel']) {
    $input = explode('-',$text); 
    $volume = $input[0];
    $price = $input[1];
    $stmt = $connection->prepare("INSERT INTO `increase_day` VALUES (NULL, ?, ?)");
    $stmt->bind_param("ii", $volume, $price);
    $stmt->execute();
    $stmt->close();
    
    sendMessage("Новая временная шкала успешно добавлена",$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    setUser();
}
if(preg_match('/^deleteDayPlan(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("Желаемый план успешно удален");
    
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       editText($message_id, 'Список расписаний пуст ',json_encode([
                'inline_keyboard' => [
                    [['text' => "Добавить новое расписание", 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"Удалить",'callback_data'=>"wizwizch"],['text'=>"Цена",'callback_data'=>"wizwizch"],['text'=>"количество дней",'callback_data'=>"wizwizch"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " токен";
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 Нажмите на нее, чтобы увидеть подробности расписания👇';
    
    editText($message_id,$msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    exit;
}
if(preg_match('/^changeDayPlanPrice(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("Введите новую цену:", $cancelKey);
    exit;
}
if(preg_match('/^changeDayPlanPrice(\d+)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        setUser();
        $stmt = $connection->prepare("UPDATE `increase_day` SET `price` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("✅Операция выполнена",$removeKeyboard);
        
        $stmt = $connection->prepare("SELECT * FROM `increase_day`");
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
    
        if($res->num_rows == 0){
           sendMessage( 'Список расписаний пуст ',json_encode([
                    'inline_keyboard' => [
                        [['text' => "Добавить новое расписание", 'callback_data' =>"addNewDayPlan"]],
                        [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]
                ]));
            exit;
        }
        $keyboard = [];
        $keyboard[] = [['text'=>"Удалить",'callback_data'=>"wizwizch"],['text'=>"Цена",'callback_data'=>"wizwizch"],['text'=>"количество дней",'callback_data'=>"wizwizch"]];
        while($cat = $res->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['volume'];
            $price=number_format($cat['price']) . " токен";
            $acount =$cat['acount'];
    
            $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
        }
        $keyboard[] = [['text' => "Добавить новое расписание", 'callback_data' =>"addNewDayPlan"]];
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
        $msg = ' 📍 Нажмите на нее, чтобы увидеть подробности расписания👇';
        
        sendMessage($msg,json_encode([
                'inline_keyboard' => $keyboard
            ]));
    
        
    }else{
        sendMessage("Введите целое значение");
    }
}
if(preg_match('/^changeDayPlanDay(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("Введите новый день:", $cancelKey);
    exit;
}
if(preg_match('/^changeDayPlanDay(\d+)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']) {
    setUser();
    $stmt = $connection->prepare("UPDATE `increase_day` SET `volume` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("✅Операция выполнена",$removeKeyboard);
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       sendMessage( 'Список расписаний пуст ',json_encode([
                'inline_keyboard' => [
                    [['text' => "Добавить новое расписание", 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"Удалить",'callback_data'=>"wizwizch"],['text'=>"Цена",'callback_data'=>"wizwizch"],['text'=>"количество дней",'callback_data'=>"wizwizch"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " токен";
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => "Добавить новое расписание", 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 Нажмите на нее, чтобы увидеть подробности расписания👇';
    
    sendMessage($msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    
}
if($data == 'volumePlanSettings' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       editText($message_id, 'Список планов тома пуст. ',json_encode([
                'inline_keyboard' => [
                    [['text' => "Добавляем новый объемный план", 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"backplan"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"Удалить",'callback_data'=>"wizwizch"],['text'=>"Цена",'callback_data'=>"wizwizch"],['text'=>"Объем объема",'callback_data'=>"wizwizch"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " токен";
        
        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => "Добавляем новый объемный план", 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "backplan"]];
    $msg = ' 📍 Чтобы просмотреть подробную информацию о плане томов, нажмите на него.👇';
    
    $res = editText($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    exit;
}
if($data=='addNewVolumePlan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("Введите его объем и цену ниже :
10-30000

Первый объём объёма (10) гигабайт
Вторая цена (30 000) томанов.
 ",$cancelKey);
 exit;
}
if($userInfo['step'] == "addNewVolumePlan" and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $input = explode('-',$text); 
    $volume = $input[0];
    $price = $input[1];
    $stmt = $connection->prepare("INSERT INTO `increase_plan` VALUES (NULL, ? ,?)");
    $stmt->bind_param("ii",$volume,$price);
    $stmt->execute();
    $stmt->close();
    
    sendMessage("Новый план тома успешно добавлен",$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    setUser();
}
if(preg_match('/^deleteVolumePlan(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("Желаемый план успешно удален");
    
    
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       editText($message_id, 'Список планов тома пуст. ',json_encode([
                'inline_keyboard' => [
                    [['text' => "Добавляем новый объемный план", 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"Удалить",'callback_data'=>"wizwizch"],['text'=>"Цена",'callback_data'=>"wizwizch"],['text'=>"Объем объема",'callback_data'=>"wizwizch"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " токен";
        
        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => "Добавляем новый объемный план", 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 Чтобы просмотреть подробную информацию о плане томов, нажмите на него.👇';
    
    $res = editText($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/^changeVolumePlanPrice(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("Введите новую цену:", $cancelKey);
    exit;
}
if(preg_match('/^changeVolumePlanPrice(\d+)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] and ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $pid=$match[1];
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `increase_plan` SET `price` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $pid);
        $stmt->execute();
        $stmt->close();
        sendMessage("Операция выполнена",$removeKeyboard);
        
        setUser();
        $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
        $stmt->execute();
        $plans = $stmt->get_result();
        $stmt->close();
        
        if($plans->num_rows == 0){
           sendMessage( 'Список планов тома пуст. ',json_encode([
                    'inline_keyboard' => [
                        [['text' => "Добавляем новый объемный план", 'callback_data' =>"addNewVolumePlan"]],
                        [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                        ]]));
            exit;
        }
        $keyboard = [];
        $keyboard[] = [['text'=>"Удалить",'callback_data'=>"wizwizch"],['text'=>"Цена",'callback_data'=>"wizwizch"],['text'=>"Объем объема",'callback_data'=>"wizwizch"]];
        while ($cat = $plans->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['volume'];
            $price=number_format($cat['price']) . " токен";
            
            $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
        }
        $keyboard[] = [['text' => "Добавляем новый объемный план", 'callback_data' =>"addNewVolumePlan"]];
        $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
        $msg = ' 📍 Чтобы просмотреть подробную информацию о плане томов, нажмите на него.👇';
        
        $res = sendMessage($msg,json_encode([
                'inline_keyboard' => $keyboard
            ]));
    }else{
        sendMessage("Введите целое значение");
    }
}
if(preg_match('/^changeVolumePlanVolume(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("Введите новый том:", $cancelKey);
    exit;
}
if(preg_match('/^changeVolumePlanVolume(\d+)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $pid=$match[1];
    $stmt = $connection->prepare("UPDATE `increase_plan` SET `volume` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $text, $pid);
    $stmt->execute();
    $stmt->close();
    sendMessage("✅Операция выполнена",$removeKeyboard);
    setUser();

    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       sendMessage( 'Список планов тома пуст. ',json_encode([
                'inline_keyboard' => [
                    [['text' => "Добавляем новый объемный план", 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"Удалить",'callback_data'=>"wizwizch"],['text'=>"Цена",'callback_data'=>"wizwizch"],['text'=>"Объем объема",'callback_data'=>"wizwizch"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " токен";
        
        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => "Добавляем новый объемный план", 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 Чтобы просмотреть подробную информацию о плане томов, нажмите на него.👇';
    
    $res = sendMessage( $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    
}
if(preg_match('/^supportCat(.*)/',$data,$match)){
    delMessage();
    sendMessage($mainValues['enter_ticket_title'], $cancelKey);
    setUser("newTicket_" . $match[1]);
}
if(preg_match('/^newTicket_(.*)/',$userInfo['step'],$match)  and $text!=$buttonValues['cancel']){
    file_put_contents("$from_id.txt",$text);
	setUser("sendTicket_" . $match[1]);
    sendMessage($mainValues['enter_ticket_description']);
}
if(preg_match('/^sendTicket_(.*)/',$userInfo['step'],$match)  and $text!=$buttonValues['cancel']){
    if(isset($text) || isset($update->message->photo)){
        $ticketCat = $match[1];
        
        $ticketTitle = file_get_contents("$from_id.txt");
        $time = time();
    
        $ticketTitle = str_replace(["/","'","#"],['\/',"\'","\#"],$ticketTitle);
        $stmt = $connection->prepare("INSERT INTO `chats` (`user_id`,`create_date`, `title`,`category`,`state`,`rate`) VALUES 
                            (?,?,?,?,'0','0')");
        $stmt->bind_param("iiss", $from_id, $time, $ticketTitle, $ticketCat);
        $stmt->execute();
        $inserId = $stmt->get_result();
        $chatRowId = $stmt->insert_id;
        $stmt->close();
        
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"پاسخ",'callback_data'=>"reply_{$chatRowId}"]]
            ]]);
        if(isset($text)){
            $txt = "Новый тикет:\n\nکاربر: <a href='tg://user?id=$from_id'>$first_name</a>\nимя пользователя: @$username\nID: $from_id\n\nТема тикета: $ticketCat\n\nНазвание тикета: " .$ticketTitle . "\nТекст тикета: $text";
            $text = str_replace(["/","'","#"],['\/',"\'","\#"],$text);
            $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                        (?,?,'USER',?)");
            $stmt->bind_param("iis", $chatRowId, $time, $text);
            sendMessage($txt,$keys,"html", $admin);
        }else{
            $txt = "Новый тикет:\n\nпользователь: <a href='tg://user?id=$from_id'>$first_name</a>\nимя пользователя: @$username\nID: $from_id\n\nТема тикета: $ticketCat\n\nНазвание тикета: " .$ticketTitle . "\nТекст тикета: $caption";
            $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                        (?,?,'USER',?)");
            $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
            $stmt->bind_param("iis", $chatRowId, $time, $text);
            sendPhoto($fileid, $txt,$keys, "HTML", $admin);
        }
        $stmt->execute();
        $stmt->close();
        
        sendMessage("Ваше сообщение успешно зарегистрировано",$removeKeyboard,"HTML");
        sendMessage("Пожалуйста, выберите один из ключей ниже",getMainKeys());
            
        unlink("$from_id.txt");
    	setUser("none");
    }else{
        sendMessage("Требуемое сообщение не поддерживается");
    }
    
}
if($data== "usersOpenTickets" || $data == "userAllTickets"){
    if($data== "usersOpenTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` != 2 AND `user_id` = ? ORDER BY `state` ASC, `create_date` DESC");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $ticketList = $stmt->get_result();
        $stmt->close();
        $type = 2;
    }elseif($data == "userAllTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `user_id` = ? ORDER BY `state` ASC, `create_date` DESC");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $ticketList = $stmt->get_result();
        $stmt->close();
        $type = "all";
    }
	$allList = $ticketList->num_rows;
	$cont = 5;
	$current = 0;
	$keys = array();
	setUser("none");


	if($allList>0){
        while($row = $ticketList->fetch_assoc()){
		    $current++;
		    
            $rowId = $row['id'];
            $title = $row['title'];
            $category = $row['category'];
	        $state = $row['state'];

            $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC");
            $stmt->bind_param("i", $rowId);
            $stmt->execute();
            $ticketInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $lastmsg = $ticketInfo['text'];
            $sentType = $ticketInfo['msg_type']=="ADMIN"?"Админ":"пользователь";
            if($state !=2){
                $keys = [
                        [['text'=>"Закрыть тикет 🗳",'callback_data'=>"closeTicket_$rowId"],['text'=>"Ответ на тикет 📝",'callback_data'=>"replySupport_{$rowId}"]],
                        [['text'=>"Последние сообщения 📩",'callback_data'=>"latestMsg_$rowId"]]
                        ];
            }
            else{
                $keys = [
                    [['text'=>"Последние сообщения 📩",'callback_data'=>"latestMsg_$rowId"]]
                    ];
            }
                
            if(isset(json_decode($lastmsg,true)['file_id'])){
                $info = json_decode($lastmsg,true);
                $fileid = $info['file_id'];
                $caption = $info['caption'];
                $txt ="🔘 Проблема: $title
            		💭 Группировка:  {$category}
            		\n
            		$sentType : $caption";
                sendPhoto($fileid, $txt,json_encode(['inline_keyboard'=>$keys]), "HTML");
            }else{
                sendMessage(" 🔘 Проблема: $title
            		💭 Группировка:  {$category}
            		\n
            		$sentType : $lastmsg",json_encode(['inline_keyboard'=>$keys]),"HTML");
            }

			if($current>=$cont){
			    break;
			}
        }
        
		if($allList > $cont){
		    sendmessage("Больше товаров",json_encode(['inline_keyboard'=>[
                		        [['text'=>"получать",'callback_data'=>"moreTicket_{$type}_{$cont}"]]
                		        ]]),"HTML");
		}
	}else{
	    alert("Тикет не найден");
        exit();
	}
}
if(preg_match('/^closeTicket_(\d+)/',$data,$match) and  $from_id != $admin){
    $chatRowId = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $from_id = $ticketInfo['user_id'];
    $title = $ticketInfo['title'];
    $category = $ticketInfo['category'];
        

    $stmt = $connection->prepare("UPDATE `chats` SET `state` = 2 WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $stmt->close();
    
    editKeys();

    $ticketClosed = " $title : $category \n\n" . "Этот тикет закрыт. Проголосуйте за этот тикет.";;
    
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"Очень плохо 😠",'callback_data'=>"rate_{$chatRowId}_1"]],
        [['text'=>"Плохо 🙁",'callback_data'=>"rate_{$chatRowId}_2"]],
        [['text'=>"Хорошо 😐",'callback_data'=>"rate_{$chatRowId}_3"]],
        [['text'=>"Очень хорошо 😃",'callback_data'=>"rate_{$chatRowId}_4"]],
        [['text'=>"Отлично 🤩",'callback_data'=>"rate_{$chatRowId}_5"]]
        ]]);
    sendMessage($ticketClosed,$keys,'html');
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"$from_id",'callback_data'=>"wizwizch"],
            ['text'=>"ID пользователя",'callback_data'=>'wizwizch']
        ],
        [
            ['text'=>$first_name??" ",'callback_data'=>"wizwizch"],
            ['text'=>"имя пользователя",'callback_data'=>'wizwizch']
        ],
        [
            ['text'=>"$title",'callback_data'=>'wizwizch'],
            ['text'=>"Заголовок",'callback_data'=>'wizwizch']
        ],
        [
            ['text'=>"$category",'callback_data'=>'wizwizch'],
            ['text'=>"Группировка",'callback_data'=>'wizwizch']
        ],
        ]]);
    sendMessage("☑️| Заявка была закрыта пользователем",$keys,"HTML",$admin);

}
if(preg_match('/^replySupport_(.*)/',$data,$match)){
    delMessage();
    sendMessage("💠Пожалуйста, отправьте ваше сообщение просто и лаконично!",$cancelKey);
	setUser("sendMsg_" . $match[1]);
}
if(preg_match('/^sendMsg_(.*)/',$userInfo['step'],$match)  and $text!=$buttonValues['cancel']){
    $ticketRowId = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $ticketRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $ticketTitle = $ticketInfo['title'];
    $ticketCat = $ticketInfo['category'];



    $time = time();
    if(isset($text)){
        $txt = "Новое сообщение:\n[$ticketTitle] <i>{$ticketCat}</i>\n\nпользователь: <a href='tg://user?id=$from_id'>$first_name</a>\nимя пользователя: $username\nID: $from_id\n" . "\nТекст сообщения: $text";
    
        $text = str_replace(["/","'","#"],['\/',"\'","\#"],$text);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'USER',?)");
        $stmt->bind_param("iis",$ticketRowId, $time, $text);
        sendMessage($txt,json_encode(['inline_keyboard'=>[
            [['text'=>"Ответ",'callback_data'=>"reply_{$ticketRowId}"]]
            ]]),"HTML",$admin);
    }else{
        $txt = "Новое сообщение:\n[$ticketTitle] <i>{$ticketCat}</i>\n\nпользователь: <a href='tg://user?id=$from_id'>$first_name</a>\nимя пользователя: $username\nID: $from_id\n" . "\nТекст сообщения: $caption";
        
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'USER',?)");
        $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
        $stmt->bind_param("iis", $ticketRowId, $time, $text);
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"Ответ",'callback_data'=>"reply_{$ticketRowId}"]]
            ]]);
        sendPhoto($fileid, $txt,$keys, "HTML", $admin);
    }
    $stmt->execute();
    $stmt->close();
                
    sendMessage("Ваше сообщение успешно зарегистрировано",getMainKeys(),"HTML");
	setUser("none");
}
if(preg_match("/^rate_+([0-9])+_+([0-9])/",$data,$match)){
    $rowChatId = $match[1];
    $rate = $match[2];
    
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i",$rowChatId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $title = $ticketInfo['title'];
    $category = $ticketInfo['category'];
    
    
    $stmt = $connection->prepare("UPDATE `chats` SET `rate` = $rate WHERE `id` = ?");
    $stmt->bind_param("i", $rowChatId);
    $stmt->execute();
    $stmt->close();
    editText($message_id,"✅");
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"голосование по билетам",'callback_data'=>"wizwizch"]
            ],
        ]]);

    sendMessage("📨| Голосуйте за билет

👤 Числовой идентификатор: $from_id
Имя пользователя: $first_name
Имя пользователя: $username
Название: $title
⚜️ Категория: $category
❤️ Оценка: $rate
 ⁮⁮
    ",$keys,"HTML",$admin);
}
if($data=="ticketsList" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $ticketSection = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"Открытые тикеты",'callback_data'=>"openTickets"],
            ['text'=>"Новые тикеты",'callback_data'=>"newTickets"]
            ],
        [
            ['text'=>"Все тикеты",'callback_data'=>"allTickets"],
            ['text'=>"Категория тикетов",'callback_data'=>"ticketsCategory"]
            ],
        [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]]
        ]]);
    editText($message_id, "Добро пожаловать в раздел билетов. 
    
🚪 /start
    ",$ticketSection);
}
if($data=='ticketsCategory' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    $keys = array();
    $keys[] = [['text'=>"Удалить",'callback_data'=>"wizwizch"],['text'=>"Группировка",'callback_data'=>"wizwizch"]];
    
    if($ticketCategory->num_rows>0){
        while($row = $ticketCategory->fetch_assoc()){
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>"❌",'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>"Категория не найдена",'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>"Добавить категорию",'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    editText($message_id,"Категория тикетов",$keys);
}
if($data=="addTicketCategory" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser('addTicketCategory');
    editText($message_id,"Пожалуйста, введите название категории");
}
if ($userInfo['step']=="addTicketCategory" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
	$stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES ('TICKETS_CATEGORY', ?)");	
	$stmt->bind_param("s", $text);
	$stmt->execute();
	$stmt->close();
    setUser();
    sendMessage($mainValues['saved_successfuly']);
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    $keys[] = [['text'=>"Удалить",'callback_data'=>"wizwizch"],['text'=>"Группировка",'callback_data'=>"wizwizch"]];
    
    if($ticketCategory->num_rows>0){
        while ($row = $ticketCategory->fetch_assoc()){
            
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>"❌",'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>"Категория не найдена",'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>"Добавить категорию",'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    sendMessage("Категория тикетов",$keys);
}
if(preg_match("/^delTicketCat_(\d+)/",$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert("Удален успешно");
        

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    $keys[] = [['text'=>"Удалить",'callback_data'=>"wizwizch"],['text'=>"Группировка",'callback_data'=>"wizwizch"]];
    
    if($ticketCategory->num_rows>0){
        while ($row = $ticketCategory->fetch_assoc()){
            
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>"❌",'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>"Категория не найдена",'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>"Добавить категорию",'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    editText($message_id, "Категория тикетов",$keys);
}
if(($data=="openTickets" or $data=="newTickets" or $data == "allTickets")  and  $from_id ==$admin){
    if($data=="openTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` != 2 ORDER BY `state` ASC, `create_date` DESC");
        $type = 2;
    }elseif($data=="newTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` = 0 ORDER BY `create_date` DESC");
        $type = 0;
    }elseif($data=="allTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` ORDER BY `state` ASC, `create_date` DESC");
        $type = "all";
    }
    $stmt->execute();
    $ticketList = $stmt->get_result();
    $stmt->close();
	$allList =$ticketList->num_rows;
	$cont = 5;
	$current = 0;
	$keys = array();
	if($allList>0){
        while ($row = $ticketList->fetch_assoc()){
		    $current++;
		    
            $rowId = $row['id'];
            $admin = $row['user_id'];
            $title = $row['title'];
            $category = $row['category'];
	        $state = $row['state'];
	        $username = bot('getChat',['chat_id'=>$admin])->result->first_name ?? " ";

            $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC");
            $stmt->bind_param("i",$rowId);
            $stmt->execute();
            $ticketInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $lastmsg = $ticketInfo['text'];
            $sentType = $ticketInfo['msg_type']=="USER"?"пользователь":"Админ";
            
            if($state !=2){
                $keys = [
                        [['text'=>"Закрыть тикет",'callback_data'=>"closeTicket_$rowId"],['text'=>"Ответ",'callback_data'=>"reply_{$rowId}"]],
                        [['text'=>"Последние сообщения",'callback_data'=>"latestMsg_$rowId"]]
                        ];
            }
            else{
                $keys = [[['text'=>"Последние сообщения",'callback_data'=>"latestMsg_$rowId"]]];
                $rate = "\nГолосование: ". $row['rate'];
            }
            
            sendMessage("ID пользователя: $admin\nимя пользователя: $username\nГруппировка: $category $rate\n\nПроблема: $title\nПоследнее сообщение:\n[$sentType] $lastmsg",
                json_encode(['inline_keyboard'=>$keys]),"html");

			if($current>=$cont){
			    break;
			}
        }
        
		if($allList > $cont){
		    $keys = json_encode(['inline_keyboard'=>[
		        [['text'=>"получать",'callback_data'=>"moreTicket_{$type}_{$cont}"]]
		        ]]);
            sendMessage("Больше товаров",$keys,"html");
		}
	}else{
        alert("Тикет не найден");
	}
}
if(preg_match('/^moreTicket_(.+)_(.+)/',$data, $match) and  ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id,$mainValues['please_wait_message']);
    $type = $match[1];
    $offset = $match[2];
    if($type=="2") $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` != 2 ORDER BY `state` ASC, `create_date` DESC");
    elseif($type=="0") $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` = 0 ORDER BY `create_date` DESC");
    elseif($type=="all") $stmt = $connection->prepare("SELECT * FROM `chats` ORDER BY `state` ASC, `create_date` DESC");
    
    $stmt->execute();
    $ticketList = $stmt->get_result();
    $stmt->close();

	$allList =$ticketList->num_rows;
	$cont = 5 + $offset;
	$current = 0;
	$keys = array();
	$rowCont = 0;
	if($allList>0){
        while ($row = $ticketList->fetch_assoc()){
            $rowCont++;
            if($rowCont>$offset){
    		    $current++;
    		    
                $rowId = $row['id'];
                $admin = $row['user_id'];
                $title = $row['title'];
                $category = $row['category'];
    	        $state = $row['state'];
    	        $username = bot('getChat',['chat_id'=>$admin])->result->first_name ?? " ";
    
                $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC");
                $stmt->bind_param("i",$rowId);
                $stmt->execute();
                $ticketInfo = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $lastmsg = $ticketInfo['text'];
                $sentType = $ticketInfo['msg_type']=="USER"?"пользователь":"Админ";
                
                if($state !=2){
                    $keys = [
                            [['text'=>"Закрыть тикет",'callback_data'=>"closeTicket_$rowId"],['text'=>"Ответ",'callback_data'=>"reply_{$rowId}"]],
                            [['text'=>"Последние сообщения",'callback_data'=>"latestMsg_$rowId"]]
                            ];
                }
                else{
                    $keys = [[['text'=>"Последние сообщения",'callback_data'=>"latestMsg_$rowId"]]];
                    $rate = "\nГолосование: ". $row['rate'];
                }
                
                sendMessage("ID пользователя: $admin\nимя пользователя: $username\nГруппировка: $category $rate\n\nПроблема: $title\nПоследнее сообщение:\n[$sentType] $lastmsg",
                    json_encode(['inline_keyboard'=>$keys]),"html");


    			if($current>=$cont){
    			    break;
    			}
            }
        }
        
		if($allList > $cont){
		    $keys = json_encode(['inline_keyboard'=>[
		        [['text'=>"получать",'callback_data'=>"moreTicket_{$type}_{$cont}"]]
		        ]]);
            sendMessage("Больше товаров",$keys);
		}
	}else{
        alert("Тикет не найден");
	}
}
if(preg_match('/^closeTicket_(\d+)/',$data,$match) and  ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $chatRowId = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $ticketInfo['user_id'];
    $title = $ticketInfo['title'];
    $category = $ticketInfo['category'];
        

    $stmt = $connection->prepare("UPDATE `chats` SET `state` = 2 WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $stmt->close();
    
    $ticketClosed = "[$title] <i>$category</i> \n\n" . "Этот тикет закрыт. Проголосуйте за этот билет.";;
    
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"Очень плохо 😠",'callback_data'=>"rate_{$chatRowId}_1"]],
        [['text'=>"Плохо 🙁",'callback_data'=>"rate_{$chatRowId}_2"]],
        [['text'=>"Хорошо 😐",'callback_data'=>"rate_{$chatRowId}_3"]],
        [['text'=>"Очень хорошо 😃",'callback_data'=>"rate_{$chatRowId}_4"]],
        [['text'=>"Отлично 🤩",'callback_data'=>"rate_{$chatRowId}_5"]]
        ]]);
    sendMessage($ticketClosed,$keys,'html', $userId);
    editKeys(json_encode(['inline_keyboard'=>[
        [['text'=>"Тикет был закрыт",'callback_data'=>"wizwizch"]]
        ]]));

}
if(preg_match('/^latestMsg_(.*)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC LIMIT 10");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $chatList = $stmt->get_result();
    $stmt->close();
    $output = "";
    while($row = $chatList->fetch_assoc()){
        $rowId = $row['id'];
        $type = $row['msg_type'] == "USER" ?"пользователь":"Админ";
        $text = $row['text'];
        if(isset(json_decode($text,true)['file_id'])) $text = "Изображение /dlPic" . $rowId; 

        $output .= "<i>[$type]</i>\n$text\n\n";
    }
    sendMessage($output, null, "html");
}
if(preg_match('/^\/dlPic(\d+)/',$text,$match)){
     $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $chatList = $stmt->get_result();
    $stmt->close();
    $output = "";
    while($row = $chatList->fetch_assoc()){
        $text = json_decode($row['text'],true);
        $fileid = $text['file_id'];
        $caption = $text['caption'];
        $chatInfoId = $row['chat_id'];
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
        $stmt->bind_param("i", $chatInfoId);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $userid = $info['user_id'];
        
        if($userid == $from_id || $from_id == $admin || $userInfo['isAdmin'] == true) sendPhoto($fileid, $caption);
    }
}
if($data == "banUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("😡 | Кто снова занят? Пришлите мне свой идентификационный номер, чтобы я мог пойти.:", $cancelKey);
    setUser($data);
}
if($data=="unbanUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("Отправьте идентификационный номер, чтобы я мог его опубликовать.", $cancelKey);
    setUser($data);
}
if($userInfo['step'] == "banUser" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $usersList = $stmt->get_result();
        $stmt->close();
        
        if($usersList->num_rows >0){
            $userState = $usersList->fetch_assoc();
            if($userState['step'] != "banned"){
                $stmt = $connection->prepare("UPDATE `users` SET `step` = 'banned' WHERE `userid` = ?");
                $stmt->bind_param("i", $text);
                $stmt->execute();
                $stmt->close();
                
                sendMessage("❌ | Ну и уходи, я заблокировал 😎😂",$removeKeyboard);
            }else{
                sendMessage("☑️ | Этот пользователь, которого уже заблокировали, что ты делаешь, несчастный человек? 😂🤣",$removeKeyboard);
            }
        }else sendMessage("Пользователь с этим идентификатором не найден");
        setUser();
        sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if($data=="mainMenuButtons" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id,"Управление кнопками главной страницы",getMainMenuButtonsKeys());
}
if(preg_match('/^delMainButton(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert("Удален успешно");
    editText($message_id,"Управление кнопками главной страницы",getMainMenuButtonsKeys());
}
if($data == "addNewMainButton" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("Пожалуйста, введите название кнопки",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addNewMainButton" && $text != $buttonValues['cancel']){
    if(!isset($update->message->text)){
        sendMessage("Пожалуйста, отправляйте только текст");
        exit();
    }
    sendMessage("Пожалуйста, введите кнопку ответа");
    setUser("setMainButtonAnswer" . $text);
}
if(preg_match('/^setMainButtonAnswer(.*)/',$userInfo['step'],$match)){
    if(!isset($update->message->text)){
        sendMessage("Пожалуйста, отправляйте только текст");
        exit();
    }
    setUser();
    
    $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES (?, ?)");
    $btn = "MAIN_BUTTONS" . $match[1];
    $stmt->bind_param("ss", $btn, $text); 
    $stmt->execute();
    $stmt->close();
    
    sendMessage("Управление кнопками главной страницы",getMainMenuButtonsKeys());
}
if($userInfo['step'] == "unbanUser" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $usersList = $stmt->get_result();
        $stmt->close();

        if($usersList->num_rows >0){
            $userState = $usersList->fetch_assoc();
            if($userState['step'] == "banned"){
                $stmt = $connection->prepare("UPDATE `users` SET `step` = 'none' WHERE `userid` = ?");
                $stmt->bind_param("i", $text);
                $stmt->execute();
                $stmt->close();

                sendMessage("✅ | Я свободен, я счастлив, Нене, я надеюсь на свободу каждого 😂",$removeKeyboard);
            }else{
                sendMessage("☑️ | Этот пользователь, которого вы отправили, уже был свободен 🙁",$removeKeyboard);
            }
        }else sendMessage("Пользователь с этим идентификатором не найден");
        setUser();
        sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match("/^reply_(.*)/",$data,$match) and  ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser("answer_" . $match[1]);
    sendMessage("Пожалуйста, отправьте ваше сообщение",$cancelKey);
}
if(preg_match('/^answer_(.*)/',$userInfo['step'],$match) and  $from_id ==$admin  and $text!=$buttonValues['cancel']){
    $chatRowId = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $ticketInfo['user_id'];
    $ticketTitle = $ticketInfo['title'];
    $ticketCat = $ticketInfo['category'];
    
    $time = time();

    
    if(isset($text)){
        $ticketTitle = str_replace(["/","'","#"],['\/',"\'","\#"],$ticketTitle);
        $text = str_replace(["/","'","#"],['\/',"\'","\#"],$text);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'ADMIN',?)");
        $stmt->bind_param("iis", $chatRowId, $time, $text);
        
        sendMessage("\[$ticketTitle] _{$ticketCat}_\n\n" . $text,json_encode(['inline_keyboard'=>[
            [
                ['text'=>'Ответ на тикет 📝','callback_data'=>"replySupport_$chatRowId"],
                ['text'=>"Закрыть тикет 🗳",'callback_data'=>"closeTicket_$chatRowId"]
                ]
            ]]),"MarkDown", $userId);        
    }else{
        $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'ADMIN',?)");
        $stmt->bind_param("iis", $chatRowId, $time, $text);
        
        $keyboard = json_encode(['inline_keyboard'=>[
            [
                ['text'=>'Ответ на тикет 📝','callback_data'=>"replySupport_$chatRowId"],
                ['text'=>"Закрыть тикет 🗳",'callback_data'=>"closeTicket_$chatRowId"]
                ]
            ]]);
            
        sendPhoto($fileid, "\[$ticketTitle] _{$ticketCat}_\n\n" . $caption,$keyboard, "MarkDown", $userId);
    }
    $stmt->execute();
    $stmt->close();
    
    $stmt = $connection->prepare("UPDATE `chats` SET `state` = 1 WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $stmt->close();
    
    setUser();
    sendMessage("Ваше сообщение было успешно отправлено ✅",$removeKeyboard);
}
if(preg_match('/freeTrial(\d+)/',$data,$match)) {
    $id = $match[1];
 
    if($userInfo['freetrial'] == 'used' and !($from_id == $admin) && json_decode($userInfo['discount_percent'],true)['normal'] != "100"){
        alert('⚠️Вы уже получили свой бесплатный подарок');
        exit;
    }
    delMessage();
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = $file_detail['days'];
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $volume = $file_detail['volume'];
    $protocol = $file_detail['protocol'];
    $price = $file_detail['price'];
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];
    $netType = $file_detail['type'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    $agentBought = false;
    if($userInfo['temp'] == "agentBuy" || $userInfo['temp'] == "agentMuchBuy"){
        $agentBought = true;
        
        
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
        else $discount = $discounts['servers'][$server_id]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
    }
    
    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0){
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }
    
    $uniqid = generateRandomString(42,$protocol); 

    $savedinfo = file_get_contents('settings/temp.txt');
    $savedinfo = explode('-',$savedinfo);
    $port = $savedinfo[0] + 1;
    $last_num = $savedinfo[1] + 1;

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $srv_remark = $serverInfo['remark'];
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $portType = $stmt->get_result()->fetch_assoc()['port_type'];
    $stmt->close();

    if($from_id == $admin && !empty($userInfo['temp'])){
        $remark = $userInfo['temp'];
        setUser('','temp');
    }else{
        if($botState['remark'] == "digits"){
            $rnd = rand(10000,99999);
            $remark = "{$srv_remark}-{$rnd}";
        }else{
            $rnd = rand(1111,99999);
            $remark = "{$srv_remark}-{$from_id}-{$rnd}";
        }
    }
    
    if($portType == "auto"){
        file_put_contents('settings/temp.txt',$port.'-'.$last_num);
    }else{
        $port = rand(1111,65000);
    }
    if($inbound_id == 0){    
        $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $id); 
        if(!$response->success){
            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $id);
        } 
    }else {
        $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $id); 
        if(!$response->success){
            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $id);
        }
    }
    if(is_null($response)){
        alert('❌ | 🥺 Голам, соединение с сервером не установлено, сообщите об этом администратору ...');
        exit;
    }
	if($response == "inbound not Found"){
        alert("❌ | 🥺 Линия (inbound) с идентификатором $inbound_id не существует на этом сервере, сообщите менеджеру...");
		exit;
	}
	if(!$response->success){
        alert('❌ | 😮 Ого, произошла ошибка, пожалуйста, срочно сообщите менеджеру....');
        sendMessage("Ошибка сервера {$serverInfo['title']}:\n\n" . ($response->msg), null, null, $admin);
        exit;
    }
    alert($mainValues['sending_config_to_user']);
    $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
	include 'phpqrcode/qrlib.php';
    $token = RandomString(30);
    $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);
    foreach($vraylink as $vray_link){
        $acc_text = "😍 Ваш новый заказ
📡 Протокол: $protocol
🔮 Имя сервиса: $remark
🔋 Объем услуги: $volume Гб
⏰ Продолжительность услуги: $days дней
" . ($botState['configLinkState'] != "off"?"
💝 config : <code>$vray_link</code>":"");
if($botState['subLinkState'] == "on") $acc_text .= "

🔋 Volume web: <code> $botUrl"."search.php?id=".$uniqid."</code>

\n🌐 subscription : <code>$subLink</code>";
    
        $file = RandomString().".png";
        $ecc = 'L'; 
        $pixel_Size = 11;
        $frame_Size = 0;
        QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_size);
    	addBorderImage($file);
    	
    	
        $backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

        sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML");
        unlink($file);
    }
    $vray_link = json_encode($vraylink);
	$stmt = $connection->prepare("INSERT INTO `orders_list` 
	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?)");
	$stmt->bind_param("isiiisssisiiii", $from_id, $token, $id, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $price, $date, $rahgozar, $agentBought);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();
    
    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }

    setUser('used','freetrial');    
}
if(preg_match('/^showMainButtonAns(\d+)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    editText($message_id,$info['value'],json_encode(['inline_keyboard'=>[
        [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]]
        ]]));
}
if($data=="rejectedAgentList"){
    $keys = getRejectedAgentList();
    if($keys != null){
        editText($message_id,"Список пользователей, отвергнутых агентством",$keys);
    }else alert("Пользователь не найден");
}
if(preg_match('/^releaseRejectedAgent(\d+)/',$data,$match)){
    $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 0 WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert($mainValues['saved_successfuly']);
    $keys = getRejectedAgentList();
    if($keys != null){
        editText($message_id,"Список пользователей, отвергнутых агентством",$keys);
    }else editText($message_id,"Пользователь не найден",json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"managePanel"]]]]));
}
if($data=="showUUIDLeft" && ($botState['searchState']=="on" || $from_id== $admin)){
    delMessage();
    sendMessage($mainValues['send_config_uuid'],$cancelKey);
    setUser('showAccount');
}
if($userInfo['step'] == "showAccount" and $text != $buttonValues['cancel']){
    if(preg_match('/^vmess:\/\/(.*)/',$text,$match)){
        $jsonDecode = json_decode(base64_decode($match[1]),true);
        $text = $jsonDecode['id'];
    }elseif(preg_match('/^vless:\/\/(.*?)\@/',$text,$match)){
        $text = $match[1];
    }elseif(preg_match('/^trojan:\/\/(.*?)\@/',$text,$match)){
        $text = $match[1];
    }elseif(!preg_match('/[a-f0-9]{8}\-[a-f0-9]{4}\-4[a-f0-9]{3}\-(8|9|a|b)[a-f0-9]{3}\-[a-f0-9]{12}/', $text)){
        sendMessage($mainValues['not_correct_text']);
        exit();
    }
    
    sendMessage($mainValues['please_wait_message'], $removeKeyboard);
    $stmt = $connection->prepare("SELECT * FROM `server_config`");
    $stmt->execute();
    $serversList = $stmt->get_result();
    $stmt->close();
    $found = false;
    while($row = $serversList->fetch_assoc()){
        $serverId = $row['id'];

        $response = getJson($serverId);
        if($response->success){
            
            $list = json_encode($response->obj);
            
            if(strpos($list, $text)){
                setUser();
                $found = true;
                $list = $response->obj;
                if(!isset($list[0]->clientStats)){
                    foreach($list as $keys=>$packageInfo){
                    	if(strpos($packageInfo->settings, $text)!=false){
                    	    $configLocation = ["remark"=> $packageInfo->remark, "uuid" =>$text];
                    	    $remark = $packageInfo->remark;
                            $upload = sumerize($packageInfo->up);
                            $download = sumerize($packageInfo->down);
                            $state = $packageInfo->enable == true?$buttonValues['active']:$buttonValues['deactive'];
                            $totalUsed = sumerize($packageInfo->up + $packageInfo->down);
                            $total = $packageInfo->total!=0?sumerize($packageInfo->total):"неограниченный";
                            $expiryTime = $packageInfo->expiryTime != 0?jdate("Y-m-d H:i:s",substr($packageInfo->expiryTime,0,-3)):"неограниченный";
                            $leftMb = $packageInfo->total!=0?sumerize($packageInfo->total - $packageInfo->up - $packageInfo->down):"неограниченный";
                            $expiryDay = $packageInfo->expiryTime != 0?
                                floor(
                                    (substr($packageInfo->expiryTime,0,-3)-time())/(60 * 60 * 24))
                                    :
                                    "неограниченный";
                            if(is_numeric($expiryDay)){
                                if($expiryDay<0) $expiryDay = 0;
                            }
                            break;
                    	}
                    }
                }
                else{
                    $keys = -1;
                    $settings = array_column($list,'settings');
                    foreach($settings as $key => $value){
                    	if(strpos($value, $text)!= false){
                    		$keys = $key;
                    		break;
                    	}
                    }
                    if($keys == -1){
                        $found = false;
                        break;
                    }
                    $clientsSettings = json_decode($list[$keys]->settings,true)['clients'];
                    if(!is_array($clientsSettings)){
                        sendMessage("Извините, что-то пошло не так, попробуйте еще раз");
                        exit();
                    }
                    $settingsId = array_column($clientsSettings,'id');
                    $settingKey = array_search($text,$settingsId);
                    
                    if(!isset($clientsSettings[$settingKey]['email'])){
                        $packageInfo = $list[$keys];
                	    $configLocation = ["remark" => $packageInfo->remark ,"uuid" =>$text];
                	    $remark = $packageInfo->remark;
                        $upload = sumerize($packageInfo->up);
                        $download = sumerize($packageInfo->down);
                        $state = $packageInfo->enable == true?$buttonValues['active']:$buttonValues['deactive'];
                        $totalUsed = sumerize($packageInfo->up + $packageInfo->down);
                        $total = $packageInfo->total!=0?sumerize($packageInfo->total):"неограниченный";
                        $expiryTime = $packageInfo->expiryTime != 0?jdate("Y-m-d H:i:s",substr($packageInfo->expiryTime,0,-3)):"неограниченный";
                        $leftMb = $packageInfo->total!=0?sumerize($packageInfo->total - $packageInfo->up - $packageInfo->down):"неограниченный";
                        if(is_numeric($leftMb)){
                            if($leftMb<0){
                                $leftMb = 0;
                            }else{
                                $leftMb = sumerize($packageInfo->total - $packageInfo->up - $packageInfo->down);
                            }
                        }

                        
                        $expiryDay = $packageInfo->expiryTime != 0?
                            floor(
                                (substr($packageInfo->expiryTime,0,-3)-time())/(60 * 60 * 24)
                                ):
                                "неограниченный";    
                        if(is_numeric($expiryDay)){
                            if($expiryDay<0) $expiryDay = 0;
                        }
                    }else{
                        $email = $clientsSettings[$settingKey]['email'];
                        $clientState = $list[$keys]->clientStats;
                        $emails = array_column($clientState,'email');
                        $emailKey = array_search($email,$emails);                    
             
                        // if($clientState[$emailKey]->total != 0 || $clientState[$emailKey]->up != 0  ||  $clientState[$emailKey]->down != 0 || $clientState[$emailKey]->expiryTime != 0){
                        if(count($clientState) > 1){
                    	    $configLocation = ["id" => $list[$keys]->id, "remark"=>$email, "uuid"=>$text];
                            $upload = sumerize($clientState[$emailKey]->up);
                            $download = sumerize($clientState[$emailKey]->down);
                            $total = $clientState[$emailKey]->total==0 && $list[$keys]->total !=0?$list[$keys]->total:$clientState[$emailKey]->total;
                            $leftMb = $total!=0?($total - $clientState[$emailKey]->up - $clientState[$emailKey]->down):"неограниченный";
                            if(is_numeric($leftMb)){
                                if($leftMb<0){
                                    $leftMb = 0;
                                }else{
                                    $leftMb = sumerize($total - $clientState[$emailKey]->up - $clientState[$emailKey]->down);
                                }
                            }
                            $totalUsed = sumerize($clientState[$emailKey]->up + $clientState[$emailKey]->down);
                            $total = $total!=0?sumerize($total):"неограниченный";
                            $expTime = $clientState[$emailKey]->expiryTime == 0 && $list[$keys]->expiryTime?$list[$keys]->expiryTime:$clientState[$emailKey]->expiryTime;
                            $expiryTime = $expTime != 0?jdate("Y-m-d H:i:s",substr($expTime,0,-3)):"неограниченный";
                            $expiryDay = $expTime != 0?
                                floor(
                                    ((substr($expTime,0,-3)-time())/(60 * 60 * 24))
                                    ):
                                    "неограниченный";
                            if(is_numeric($expiryDay)){
                                if($expiryDay<0) $expiryDay = 0;
                            }
                            $state = $clientState[$emailKey]->enable == true?$buttonValues['active']:$buttonValues['deactive'];
                            $remark = $email;
                        }
                        else{
                            $clientUpload = $clientState[$emailKey]->up;
                            $clientDownload = $clientState[$emailKey]->down;
                            $clientTotal = $clientState[$emailKey]->total;
                            $clientExpTime = $clientState[$emailKey]->expiryTime;
                            
                            $up = $list[$keys]->up;
                            $down = $list[$keys]->down;
                            $total = $list[$keys]->total;
                            $expiry = $list[$keys]->expiryTime;
                            
                            if(($clientTotal != 0 || $clientTotal != null) && ($clientExpTime != 0 || $clientExpTime != null)){
                                $up = $clientUpload;
                                $down = $clientDownload;
                                $total = $clientTotal;
                                $expiry = $clientExpTime;
                            }

                            $upload = sumerize($up);
                            $download = sumerize($down);
                            $configLocation = ["uuid" => $text, "remark"=>$list[$keys]->remark];
                            $leftMb = $total!=0?($total - $up - $down):"неограниченный";
                            if(is_numeric($leftMb)){
                                if($leftMb<0){
                                    $leftMb = 0;
                                }else{
                                    $leftMb = sumerize($total - $up - $down);
                                }
                            }
                            $totalUsed = sumerize($up + $down);
                            $total = $total!=0?sumerize($total):"неограниченный";
                            
                            
                            $expiryTime = $expiry != 0?jdate("Y-m-d H:i:s",substr($expiry,0,-3)):"неограниченный";
                            $expiryDay = $expiry != 0?
                                floor(
                                    ((substr($expiry,0,-3)-time())/(60 * 60 * 24))
                                    ):"неограниченный";
                            if(is_numeric($expiryDay)){
                                if($expiryDay<0) $expiryDay = 0;
                            }
                            $state = $list[$keys]->enable == true?$buttonValues['active']:$buttonValues['deactive'];
                            $remark = $list[$keys]->remark;
                        }
                    }
                }

                $keys = json_encode(['inline_keyboard'=>[
                [
                    ['text'=>$state??" ",'callback_data'=>"wizwizch"],
                    ['text'=>"🔘 Статус аккаунта 🔘",'callback_data'=>"wizwizch"],
                    ],
                [
					['text'=>$remark??" ",'callback_data'=>"wizwizch"],
                    ['text'=>"« Имя учетной записи »",'callback_data'=>"wizwizch"],
                    ],
                [
                    ['text'=>$upload?? " ",'callback_data'=>"wizwizch"],
                    ['text'=>"√ загрузить √",'callback_data'=>"wizwizch"],
                    ],
                [
                    ['text'=>$download??" ",'callback_data'=>"wizwizch"],
                    ['text'=>"√ Скачать √",'callback_data'=>"wizwizch"],
                    ],
                [
                    ['text'=>$total??" ",'callback_data'=>"wizwizch"],
                    ['text'=>"† Общий объем †",'callback_data'=>"wizwizch"],
                    ],
                [
                    ['text'=>$leftMb??" ",'callback_data'=>"wizwizch"],
                    ['text'=>"~ оставшийся объем ~",'callback_data'=>"wizwizch"],
                    ],
                [
                    ['text'=>$expiryTime??" ",'callback_data'=>"wizwizch"],
                    ['text'=>"Дата завершения",'callback_data'=>"wizwizch"],
                    ],
                [
                    ['text'=>$expiryDay??" ",'callback_data'=>"wizwizch"],
                    ['text'=>"Осталось дней",'callback_data'=>"wizwizch"],
                    ],
                (($botState['renewAccountState'] == "on" && $botState['updateConfigLinkState'] == "on")?
                    [
                        ['text'=>$buttonValues['renew_config'],'callback_data'=>"sConfigRenew" . $serverId],
                        ['text'=>$buttonValues['update_config_connection'],'callback_data'=>"sConfigUpdate" . $serverId],
                        ]:[]
                        ),
                (($botState['renewAccountState'] != "on" && $botState['updateConfigLinkState'] == "on")?
                    [
                        ['text'=>$buttonValues['update_config_connection'],'callback_data'=>"sConfigUpdate" . $serverId]
                        ]:[]
                        ),
                (($botState['renewAccountState'] == "on" && $botState['updateConfigLinkState'] != "on")?
                    [
                        ['text'=>$buttonValues['renew_config'],'callback_data'=>"sConfigRenew" . $serverId]
                        ]:[]
                        ),
                [['text'=>"Главная страница",'callback_data'=>"mainMenu"]]
                ]]);
                setUser(json_encode($configLocation,488), "temp");
                sendMessage("🔰Данные вашего аккаунта:",$keys,"MarkDown");
                break;
                

            }
        }
    }
    if(!$found){
         sendMessage("Эй, ваша информация неверна 😔",$cancelKey);
    }
}

if(preg_match('/sConfigRenew(\d+)/', $data,$match)){
    if($botState['sellState']=="off" && $from_id !=$admin){ alert($mainValues['bot_is_updating']); exit(); }
    
    alert($mainValues['please_wait_message']);
    $server_id = $match[1];
    if(empty($userInfo['temp'])){delMessage(); exit();}
    
    $configInfo = json_decode($userInfo['temp'],true);
    $inboundId = $configInfo['id']??0;
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];

    $response = getJson($server_id)->obj;
    if($response == null){delMessage(); exit();}
    if($inboundId == 0){
        foreach($response as $row){
            $clients = json_decode($row->settings)->clients;
            if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                $port = $row->port;
                $protocol = $row->protocol;
                $configReality = json_decode($row->streamSettings)->security == "reality"?"true":"false";
                break;
            }
        }
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `inbound_id` = 0 AND `protocol` = ? AND `active` = 1 AND `price` != 0 AND `rahgozar` = 0");
    }else{
        foreach($response as $row){
            if($row->id == $inboundId) {
                $port = $row->port;
                $protocol = $row->protocol;
                $configReality = json_decode($row->streamSettings)->security == "reality"?"true":"false";
                break;
            }
        }
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `inbound_id` != 0 AND `protocol` = ? AND `active` = 1 AND `price` != 0 AND `rahgozar` = 0");
    }
    
    $stmt->bind_param("is", $server_id, $protocol);
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    if($plans->num_rows > 0){
        $keyboard = [];
        while($file = $plans->fetch_assoc()){ 
            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $isReality = $stmt->get_result()->fetch_assoc()['reality'];
            $stmt->close();
            
            if($isReality == $configReality){
                $id = $file['id'];
                $name = $file['title'];
                $price = $file['price'];
                $price = ($price == 0) ? 'Бесплатно' : number_format($price).' токенов ';
                $keyboard[] = ['text' => "$name - $price", 'callback_data' => "sConfigRenewPlan{$id}_{$inboundId}"];
            }
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, "3️⃣  Шаг третий:

Выберите один из пакетов и переходите к оплате 🤲 🕋", json_encode(['inline_keyboard'=>$keyboard]));
    }else sendMessage("💡В этой категории нет контента ");
}
if(preg_match('/sConfigRenewPlan(\d+)_(\d+)/',$data, $match) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){
    $id = $match[1];
	$inbound_id = $match[2];


    if(empty($userInfo['temp'])){delMessage(); exit();}
    
    $configInfo = json_decode($userInfo['temp'],true);
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];

    alert($mainValues['receving_information']);
    delMessage();
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $respd['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $name = $catname." ".$respd['title'];
    $desc = $respd['descr'];
	$sid = $respd['server_id'];
	$keyboard = array();
    $price =  $respd['price'];
    $token = base64_encode("{$from_id}.{$id}");
    
    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'RENEW_SCONFIG' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();

    setUser('', 'temp');
    $description = json_encode(["uuid"=>$uuid, "remark"=>$remark],488);
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, ?, 'RENEW_SCONFIG', ?, ?, '0', ?, ?, 'pending')");
    $stmt->bind_param("ssiiiii", $hash_id, $description, $from_id, $id, $inbound_id, $price, $time);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();

    
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
    sendMessage(str_replace(['PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$name, $price, $desc], $mainValues['buy_subscription_detail']), json_encode(['inline_keyboard'=>$keyboard]), "HTML");
}
if(preg_match('/sConfigUpdate(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $server_id = $match[1];
    if(empty($userInfo['temp'])){delMessage(); exit();}
    
    $configInfo = json_decode($userInfo['temp'],true);
    $inboundId = $configInfo['id']??0;
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];

    $response = getJson($server_id)->obj;
    if($response == null){delMessage(); exit();}
    
    if($inboundId == 0){
        foreach($response as $row){
            $clients = json_decode($row->settings)->clients;
            if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                $port = $row->port;
                $protocol = $row->protocol;
                $netType = json_decode($row->streamSettings)->network;
                break;
            }
        }
    }else{
        foreach($response as $row){
            if($row->id == $inboundId) {
                $port = $row->port;
                $protocol = $row->protocol;
                $netType = json_decode($row->streamSettings)->network;
                break;
            }
        }
    }
    
    if($uuid == null){delMessage(); exit();}
    $vraylink = getConnectionLink($server_id, $uuid, $protocol, $remark, $port, $netType, $inboundId);
    
    if($vraylink == null){delMessage(); exit();}
    include 'phpqrcode/qrlib.php';  
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);
    foreach($vraylink as $vray_link){
        $acc_text = $botState['configLinkState'] != "off"?"<code>$vray_link</code>":".";
    
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 0;
        
        $file = RandomString() .".png";
        QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
    	addBorderImage($file);
    	
        $backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

        sendPhoto($botUrl . $file, $acc_text,null,"HTML");
        unlink($file);
    }
}

if (($data == 'addNewPlan' || $data=="addNewRahgozarPlan") and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    setUser($data);
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();
    if($data=="addNewPlan"){
        $sql = "INSERT INTO `server_plans` (`fileid`, `catid`, `server_id`, `inbound_id`, `acount`, `limitip`, `title`, `protocol`, `days`, `volume`, `type`, `price`, `descr`, `pic`, `active`, `step`, `date`)
                                            VALUES ('', 0,0,0,0, 1, '', '', 0, 0, '', 0, '', '',0,1, ?);";
    }elseif($data=="addNewRahgozarPlan"){
        $sql = "INSERT INTO `server_plans` (`fileid`, `catid`, `server_id`, `inbound_id`, `acount`, `limitip`, `title`, `protocol`, `days`, `volume`, `type`, `price`, `descr`, `pic`, `active`, `step`, `date`, `rahgozar`)
                    VALUES ('', 0,0,0,0, 1, '', '', 0, 0, '', 0, '', '',0,1, ?, 1);";
    }
    $stmt = $connection->prepare($sql);
    $stmt->bind_param("i", $time);
    $stmt->execute();
    $stmt->close();
    delMessage();
    $msg = '❗️Выберите название плана:';
    sendMessage($msg,$cancelKey);
    exit;
}
if(preg_match('/(addNewRahgozarPlan|addNewPlan)/',$userInfo['step']) and $text!=$buttonValues['cancel']){
    $catkey = [];
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent` =0 and `active`=1");
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();

    while ($cat = $cats->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $catkey[] = ["$id - $name"];
    }
    $catkey[] = [$buttonValues['cancel']];

    $step = checkStep('server_plans');

    if($step==1 and $text!=$buttonValues['cancel']){
        $msg = '🔰 Пожалуйста, укажите стоимость пакета в токенах!';
        if(strlen($text)>1){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=?,`step`=2 WHERE `active`=0 and `step`=1");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $stmt->close();
            sendMessage($msg,$cancelKey);
        }
    } 
    if($step==2 and $text!=$buttonValues['cancel']){
        $msg = '🔰Пожалуйста, выберите категорию для плана из списка ниже. ';
        if(is_numeric($text)){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `price`=?,`step`=3 WHERE `active`=0");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $stmt->close();
            sendMessage($msg,json_encode(['keyboard'=>$catkey]));
        }else{
            $msg = '‼️ Пожалуйста, введите числовое значение';
            sendMessage($msg,$cancelKey);
        }
    } 
    if($step==3 and $text!=$buttonValues['cancel']){
        $srvkey = [];
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1");
        $stmt->execute();
        $srvs = $stmt->get_result();
        $stmt->close();
        sendMessage($mainValues['please_wait_message'],$cancelKey);
        while($srv = $srvs->fetch_assoc()){
            $id = $srv['id'];
            $title = $srv['title'];
            $srvkey[] = ['text' => "$title", 'callback_data' => "selectNewPlanServer$id"];
        }
        $srvkey = array_chunk($srvkey,2);
        sendMessage("Пожалуйста, выберите один из серверов 👇 ", json_encode([
                'inline_keyboard' => $srvkey]), "HTML");
        $inarr = 0;
        foreach ($catkey as $op) {
            if (in_array($text, $op) and $text != $buttonValues['cancel']) {
                $inarr = 1;
            }
        }
        if( $inarr==1 ){
            $input = explode(' - ',$text);
            $catid = $input[0];
            $stmt = $connection->prepare("UPDATE `server_plans` SET `catid`=?,`step`=50 WHERE `active`=0");
            $stmt->bind_param("i", $catid);
            $stmt->execute();
            $stmt->close();

            sendMessage($msg,$cancelKey);
        }else{
            $msg = '‼️ Пожалуйста, выберите только один из предложенных ниже вариантов.';
            sendMessage($msg,$catkey);
        }
    } 
    if($step==50 and $text!=$buttonValues['cancel'] and preg_match('/selectNewPlanServer(\d+)/', $data,$match)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `server_id`=?,`step`=51 WHERE `active`=0");
        $stmt->bind_param("i", $match[1]);
        $stmt->execute();
        $stmt->close();

        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"🎖Выделенный порт",'callback_data'=>"withSpecificPort"]],
            [['text'=>"🎗Общий порт",'callback_data'=>"withSharedPort"]]
            ]]);
        editText($message_id, "Пожалуйста, выберите тип панели портов", $keys);
    }
    if($step==51 and $text!=$buttonValues['cancel'] and preg_match('/^with(Specific|Shared)Port/',$data,$match)){
        if($userInfo['step'] == "addNewRahgozarPlan") $msg =  "📡 | Пожалуйста, введите желаемый протокол плана (vless | vmess)";
        else $msg =  "📡 | Пожалуйста, введите желаемый протокол плана (vless | vmess | trojan)";
        editText($message_id,$msg);
        if($match[1] == "Shared"){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `step`=60 WHERE `active`=0");
            $stmt->execute();
            $stmt->close();
        }
        elseif($match[1] == "Specific"){
            $stmt = $connection->prepare("UPDATE server_plans SET step=52 WHERE active=0");
            $stmt->execute();
            $stmt->close();
        }
    }
    if($step==60 and $text!=$buttonValues['cancel']){
        if($text != "vless" && $text != "vmess" && $text != "trojan" && $userInfo['step'] == "addNewPlan"){
            sendMessage("Пожалуйста, введите только протоколы vless и vmess.",$cancelKey);
            exit();
        }
        elseif($text != "vless" && $text != "vmess" && $userInfo['step'] == "addNewRahgozarPlan"){
            sendMessage("Пожалуйста, введите только протоколы vless и vmess.",$cancelKey);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `protocol`=?,`step`=61 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();
        sendMessage("📅 | Введите период действия этого пакета в днях:");
    }
    if($step==61 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `days`=?,`step`=62 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("🔋 | Введите объем траффика этого пакета в Гб:");
    }
    if($step==62 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `volume`=?,`step`=63 WHERE `active`=0");
        $stmt->bind_param("d", $text);
        $stmt->execute();
        $stmt->close();
        sendMessage("🛡 | Пожалуйста, введите Inbound-ID из X-UI:");
    }
    if($step==63 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `active` = 0");
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        
        $response = getJson($res['server_id'])->obj;
        foreach($response as $row){
            if($row->id == $text) {
                $netType = json_decode($row->streamSettings)->network;
            }
        }        
        if(is_null($netType)){
            sendMessage("Конфигурация с этой строкой идентификатора не найдена.");
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `type` = ?, `inbound_id`=?,`step`=64 WHERE `active`=0");
        $stmt->bind_param("si", $netType, $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("Введите емкость этого inbound");
    }
    if($step==64 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount`=?,`step`=65 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("🧲 | Введите количество пользователей этого пакета (0 — неограниченно)");
    }
    if($step==65 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        $stmt = $connection->prepare("UPDATE `server_plans` SET `limitip`=?,`step`=4 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

        $msg = '🔻Напишите описание этого пакета:';
        sendMessage($msg,$cancelKey); 
    }
    if($step==52 and $text!=$buttonValues['cancel']){
        if($userInfo['step'] == "addNewPlan" && $text != "vless" && $text != "vmess" && $text != "trojan"){
            sendMessage("Введите только протоколы vless, vmess, trojan.",$cancelKey);
            exit();
        }elseif($userInfo['step'] == "addNewRahgozarPlan" && $text != "vless" && $text != "vmess"){
            sendMessage("Введите только протоколы vless и vmess.",$cancelKey);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `protocol`=?,`step`=53 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("📅 | Введите период действия этого пакета в днях:");
    }
    if($step==53 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `days`=?,`step`=54 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("🔋 | Введите объем траффика этого пакета в ГБ:");
    }
    if($step==54 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        if($userInfo['step'] == "addNewPlan"){
            $sql = ("UPDATE `server_plans` SET `volume`=?,`step`=55 WHERE `active`=0");
            $msg = "🔉 | Выберите тип транспорта этого пакета (ws | tcp | grpc) :";
        }elseif($userInfo['step'] == "addNewRahgozarPlan"){
            $sql = ("UPDATE `server_plans` SET `volume`=?, `type`='ws', `step`=4 WHERE `active`=0");
            $msg = '🔻Напишите описание этого пакета:';
        }
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("d", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage($msg);
    }
    if($step==55 and $text!=$buttonValues['cancel']){
        if($text != "tcp" && $text != "ws" && $text != "grpc"){
            sendMessage("Введите только тип (ws | tcp | grpc)");
            exit();
        }
        $stmt = $connection->prepare("UPDATE `server_plans` SET `type`=?,`step`=4 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();


        $msg = '🔻Напишите описание этого пакета:';
        sendMessage($msg,$cancelKey); 
    }
    
    if($step==4 and $text!=$buttonValues['cancel']){
        $imgtxt = '☑️ | Панель успешно зарегистрирована и создана (наслаждайтесь)';
        $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=?, `active`=1,`step`=10 WHERE `step`=4");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage($imgtxt,$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getAdminKeys());
        setUser();
    } 
}
if($data == 'backplan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $keyboard = [];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['title'];
        $keyboard[] = ['text' => "$title", 'callback_data' => "plansList$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>"➖➖➖",'callback_data'=>"wizwizch"]];
    $keyboard[] = [['text'=>'➕ Добавление выделенного и общего пакета','callback_data'=>"addNewPlan"]];
    $keyboard[] = [['text'=>'➕ Добавить гостевой пакет','callback_data'=>"addNewRahgozarPlan"]];
    $keyboard[] = [['text'=>'➕ Добавить траффик','callback_data'=>"volumePlanSettings"],['text'=>'➕ Добавить период','callback_data'=>"dayPlanSettings"]];
    $keyboard[] = [['text' => "➕ Добавить кастомный план", 'callback_data' => "editCustomPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];

    $msg = ' ☑️ Управление пакетами:';
    
    if(isset($data) and $data=='backplan') {
        editText($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]));
    }else { sendAction('typing');
        sendmessage($msg, json_encode(['inline_keyboard'=>$keyboard]));
    }
    
    
    exit;
}
if(($data=="editCustomPlan" || preg_match('/^editCustom(gbPrice|dayPrice)/',$userInfo['step'],$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!isset($data)){
        if(is_numeric($text)){
            $botState[$match[1]] = $text;
            
            $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
            $stmt->execute();
            $isExists = $stmt->get_result();
            $stmt->close();
            if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
            else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
            $newData = json_encode($botState);
            
            $stmt = $connection->prepare($query);
            $stmt->bind_param("s", $newData);
            $stmt->execute();
            $stmt->close();
            
            sendMessage($mainValues['saved_successfuly'],$removeKeyboard); 
        }else{
            sendMessage("Просто отправьте номер");
            exit();
        }
    }
    $gbPrice=number_format($botState['gbPrice']??0) . " تومان";
    $dayPrice=number_format($botState['dayPrice']??0) . " تومان";
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>$gbPrice,'callback_data'=>"editCustomgbPrice"],
            ['text'=>"Стоимость за Гб",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$dayPrice,'callback_data'=>"editCustomdayPrice"],
            ['text'=>"Стоимость в день",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"backplan"]
            ]
            
        ]]);
    if(!isset($data)){
        sendMessage("Настройки индивидуального плана",$keys);
        setUser();
    }else{
        editText($message_id,"Настройки индивидуального плана",$keys);
    }
}
if(preg_match('/^editCustom(gbPrice|dayPrice)/',$data,$match)){
    delMessage();
    $title = $match[1] == "dayPrice"?"каждый день":"каждый Гб";
    sendMessage("Пожалуйста оплатите " . $title . " токен",$cancelKey);
    setUser($data);
}
if(preg_match('/plansList(\d+)/', $data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? ORDER BY`id` ASC");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows==0){
        alert("К сожалению, вы не выбрали для этого ни одного пакета. 😑");
        exit;
    }else {
        $keyboard = [];
        while($cat = $res->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['title'];
            $keyboard[] = ['text' => "#$id $title", 'callback_data' => "planDetails$id"];
        }
        $keyboard = array_chunk($keyboard,2);
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "backplan"],];
        $msg = ' ▫️ Выберите пакет и перейдите к редактированию:';
        editText($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    }
    exit();
}
if(preg_match('/planDetails(\d+)/', $data,$match)){
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("Ничего не найдено");
        exit;
    }else editText($message_id, "Изменить настройки пакета", $keys, "HTML");
}
if(preg_match('/^wizwizplanacclist(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `fileid`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
        alert('Список пуст');
        exit;
    }
    $txt = '';
    while($order = $res->fetch_assoc()){
		$suid = $order['userid'];
		$stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
        $stmt->bind_param("i", $suid);
        $stmt->execute();
        $ures = $stmt->get_result()->fetch_assoc();
        $stmt->close();


        $date = $order['date'];
        $remark = $order['remark'];
        $date = jdate('Y-m-d H:i', $date);
        $uname = $ures['name'];
        $sold = " 🚀 ".$uname. " ($date)";
        $accid = $order['id'];
        $orderLink = json_decode($order['link'],true);
        $txt = "$sold \n  ☑️ $remark ";
        foreach($orderLink as $link){
            $txt .= $botState['configLinkState'] != "off"?"<code>".$link."</code> \n":"";
        }
        $txt .= "\n ❗ $channelLock \n";
        sendMessage($txt, null, "HTML");
    }
}
if(preg_match('/^wizwizplandelete(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("Я удалил план для тебя ☹️☑️");
    
    editText($message_id,"Выберите один из ключей ниже",getMainKeys());
}
if(preg_match('/^wizwizplanname(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 Выберите имя для нового пакета:",$cancelKey);exit;
}
if(preg_match('/^wizwizplanname(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("Я успешно изменил его для вас ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("Ничего не найдено");
        exit;
    }else sendMessage("Изменить настройки пакета", $keys);
}
if(preg_match('/^wizwizplanslimit(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 Выберите новую емкость для пакета:",$cancelKey);exit;
}
if(preg_match('/^wizwizplanslimit(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `acount`=? WHERE `id`=?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("Изменено ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("Ничего не найдено");
        exit;
    }else sendMessage("Изменить настройки пакета", $keys, "HTML");
}
if(preg_match('/^wizwizplansinobundid(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 Выберите новую строку для пакета:",$cancelKey);exit;
}
if(preg_match('/^wizwizplansinobundid(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `inbound_id`=? WHERE `id`=?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("Я успешно изменил его для вас ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("Ничего не найдено");
        exit;
    }else sendMessage("Изменить настройки пакета", $keys, "HTML");
}
if(preg_match('/^wizwizplaneditdes(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 Введите описание:",$cancelKey);exit;
}
if(preg_match('/^wizwizplaneditdes(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();


    sendMessage("Изменено ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("Ничего не найдено");
        exit;
    }else sendMessage("Изменить настройки пакета", $keys, "HTML");
}
if(preg_match('/^editDestName(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 dest \nЧтобы удалить текст введи /empty",$cancelKey);exit;
}
if(preg_match('/^editDestName(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `dest` = NULL WHERE `id`=?");
        $stmt->bind_param("i", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `dest`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage("Изменено ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("Ничего не найдено");
        exit;
    }else sendMessage("Изменить настройки пакета", $keys, "HTML");
}
if(preg_match('/^editSpiderX(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 spiderX \n Чтобы удалить текст, введите /empty",$cancelKey);exit;
}
if(preg_match('/^editSpiderX(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `spiderX`=NULL WHERE `id`=?");
        $stmt->bind_param("s", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `spiderX`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage("Изменено ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("Ничего не найдено");
        exit;
    }else sendMessage("Изменить настройки плана", $keys, "HTML");
}
if(preg_match('/^editServerNames(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 serverNames Введите как показано ниже:\n
`[
  \"yahoo.com\",
  \"www.yahoo.com\"
]`
    \n\n Чтобы удалить текст введите /empty",$cancelKey);exit;
}
if(preg_match('/^editServerNames(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `serverNames`=NULL WHERE `id`=?");
        $stmt->bind_param("s", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `serverNames`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage("Изменено ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("Ничего не найдено");
        exit;
    }else sendMessage("Изменить настройки пакета", $keys, "HTML");
}
if(preg_match('/^editFlow(\d+)/',$data, $match) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"None", 'callback_data'=>"editPFlow" . $match[1] . "_None"]],
        [['text'=>"xtls-rprx-vision", 'callback_data'=>"editPFlow" . $match[1] . "_xtls-rprx-vision"]],
        ]]);
    sendMessage("🎯 Выберите один из следующих ",$keys);exit;
}
if(preg_match('/^editPFlow(\d+)_(.*)/',$data, $match) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `flow`=? WHERE `id`=?");
    $stmt->bind_param("si", $match[2], $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("Изменено ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    editText($message_id, "Изменить настройки плана", $keys, "HTML");
}
if(preg_match('/^wizwizplanrial(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 Вы подняли цену 😂, давайте я посмотрю новую цену: ",$cancelKey);exit;
}
if(preg_match('/^wizwizplanrial(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `price`=? WHERE `id`=?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();

        sendMessage("Изменено ☺️☑️");
        setUser();
        
        $keys = getPlanDetailsKeys($match[1]);
        if($keys == null){
            alert("Ничего не найдено");
            exit;
        }else sendMessage("Изменить настройки пакета", $keys, "HTML");
    }else{
        sendMessage("Я вам говорю, введите цену, вы взяли, вы написали что-то еще 🫤 (введите цифру)");
    }
}
if(($data == 'mySubscriptions' || $data == "agentConfigsList" or preg_match('/(changeAgentOrder|changeOrdersPage)(\d+)/',$data, $match) )&& ($botState['sellState']=="on" || $from_id ==$admin)){
    $results_per_page = 50;
    if($data == "agentConfigsList" || $match[1] == "changeAgentOrder") $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1");  
    else $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1 AND `agent_bought` = 0");  
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $number_of_result= $stmt->get_result()->num_rows;
    $stmt->close();

    $number_of_page = ceil ($number_of_result / $results_per_page);
    $page = $match[2] ??1;
    $page_first_result = ($page-1) * $results_per_page;  
    
    if($data == "agentConfigsList" || $match[1] == "changeAgentOrder") $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1 ORDER BY `id` DESC LIMIT ?, ?");
    else $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1 AND `agent_bought` = 0 ORDER BY `id` DESC LIMIT ?, ?");
    $stmt->bind_param("iii", $from_id, $page_first_result, $results_per_page);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();


    if($orders->num_rows==0){
        alert($mainValues['you_dont_have_config']);
        exit;
    }
    $keyboard = [];
    while($cat = $orders->fetch_assoc()){
        $id = $cat['id'];
        $remark = $cat['remark'];
        $keyboard[] = ['text' => "$remark", 'callback_data' => "orderDetails$id"];
    }
    $keyboard = array_chunk($keyboard,1);
    
    $prev = $page - 1;
    $next = $page + 1;
    $lastpage = ceil($number_of_page/$results_per_page);
    $lpm1 = $lastpage - 1;
    
    $buttons = [];
    if ($prev > 0) $buttons[] = ['text' => "◀", 'callback_data' => (($data=="agentConfigsList" || $match[1] == "changeAgentOrder") ? "changeAgentOrder$prev":"changeOrdersPage$prev")];

    if ($next > 0 and $page != $number_of_page) $buttons[] = ['text' => "➡", 'callback_data' => (($data=="agentConfigsList" || $match[1] == "changeAgentOrder")?"changeAgentOrder$next":"changeOrdersPage$next")];   
    $keyboard[] = $buttons;
    if($data == "agentConfigsList" || $match[1] == "changeAgentOrder") $keyboard[] = [['text'=>$buttonValues['search_agent_config'],'callback_data'=>"searchAgentConfig"]];
    else $keyboard[] = [['text'=>$buttonValues['search_agent_config'],'callback_data'=>"searchMyConfig"]];
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];
    
    if(isset($data)) {
        editText($message_id, $mainValues['select_one_to_show_detail'], json_encode(['inline_keyboard'=>$keyboard]));
    }else { sendAction('typing');
        sendMessage($mainValues['select_one_to_show_detail'], json_encode(['inline_keyboard'=>$keyboard]));
    }
    exit;
}
if($data=="searchAgentConfig" || $data == "searchMyConfig" || $data=="searchUsersConfig"){
    delMessage();
    sendMessage($mainValues['send_config_remark'],$cancelKey);
    setUser($data);
}
if(($userInfo['step'] == "searchAgentConfig" || $userInfo['step'] == "searchMyConfig") && $text != $buttonValues['cancel']){
    sendMessage($mainValues['please_wait_message'], $removeKeyboard);
    if($userInfo['step'] == "searchMyConfig") $condition = "AND `agent_bought` = 0";
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ? AND `remark` LIKE CONCAT('%', ?, '%') $condition");
    $stmt->bind_param("is", $from_id, $text);
    $stmt->execute();
    $orderId = $stmt->get_result()->fetch_assoc()['id'];
    $stmt->close();
    
    $keys = getOrderDetailKeys($from_id, $orderId);
    if($keys == null) sendMessage($mainValues['no_order_found']); 
    else {
        sendMessage($keys['msg'], $keys['keyboard'], "HTML");
        setUser();
    }
}
if($userInfo['step'] == "searchUsersConfig" && $text != $buttonValues['cancel']){
    sendMessage($mainValues['please_wait_message'], $removeKeyboard); 
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `remark` LIKE CONCAT('%', ?, '%')");
    $stmt->bind_param("s", $text);
    $stmt->execute();
    $orderId = $stmt->get_result()->fetch_assoc()['id'];
    $stmt->close();
    
    $keys = getUserOrderDetailKeys($orderId);
    if($keys == null) sendMessage($mainValues['no_order_found']); 
    else {
        sendMessage($keys['msg'], $keys['keyboard'], "HTML");
        setUser();
    }
}
if(preg_match('/orderDetails(\d+)/', $data, $match) && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true))){
    $keys = getOrderDetailKeys($from_id, $match[1]);
    if($keys == null){
        alert($mainValues['no_order_found']);exit;
    }else editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
}
if($data=="cantEditGrpc"){
    alert("Вы не можете изменить эту конфигурацию!");
    exit();
}
if(preg_match('/^changeCustomPort(\d+)/',$data,$match)){
    delMessage();
    sendMessage("Пожалуйста, введите желаемый порт\nВведите 0, чтобы удалить нужный порт.", $cancelKey);
    setUser($data);
}
if(preg_match('/^changeCustomPort(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_port`= ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();  
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
         
        sendMessage("Изменить настройки пакета", getPlanDetailsKeys($match[1]));
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^changeCustomSni(\d+)/',$data,$match)){
    delMessage();
    sendMessage("Пожалуйста, введите желаемый номер телефона\nЧтобы удалить текст /empty входить", $cancelKey);
    setUser($data);
}
if(preg_match('/^changeCustomSni(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`= NULL WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
    }
    else {
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`= ? WHERE `id` = ?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();  
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
     
    sendMessage("Изменить настройки плана", getPlanDetailsKeys($match[1]));
    setUser();
}
if(preg_match('/^changeCustomPath(\d+)/',$data,$match)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_path` = IF(`custom_path` = 1, 0, 1) WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    editKeys(getPlanDetailsKeys($match[1]));
}
if(preg_match('/changeNetworkType(\d+)_(\d+)/', $data, $match)){
    $fid = $match[1];
    $oid = $match[2];
    
	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1"); 
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();


	if($respd){
		$respd = $respd->fetch_assoc(); 
		$stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $respd['catid']);
        $stmt->execute();
        $cadquery = $stmt->get_result();
        $stmt->close();


		if($cadquery) {
			$catname = $cadquery->fetch_assoc()['title'];
			$name = $catname." ".$respd['title'];
		}else $name = "$oid";
		
	}else $name = "$oid";

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();


    $date = jdate("Y-m-d H:i",$order['date']);
    $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $acc_link = $order['link'];
    $protocol = $order['protocol'];
    $server_id = $order['server_id'];
    $price = $order['amount'];
    
    $response = getJson($server_id)->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $port = $row->port;
            $netType = json_decode($row->streamSettings)->network; 
            $security = json_decode($row->streamSettings)->security;
            $netType = ($netType == 'tcp') ? 'ws' : 'tcp';
        break;
        }
    }

    if($protocol == 'trojan') $netType = 'tcp';

    $update_response = editInbound($server_id, $uuid, $uuid, $protocol, $netType);
    $vraylink = getConnectionLink($server_id, $uuid, $protocol, $remark, $port, $netType);

    $vray_link = json_encode($vraylink);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `protocol`=?,`link`=? WHERE `id`=?");
    $stmt->bind_param("ssi", $protocol, $vray_link, $oid);
    $stmt->execute();
    $stmt->close();
    
    $keys = getOrderDetailKeys($from_id, $oid);
    editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
}
if($data=="changeProtocolIsDisable"){
    alert("Переключение протоколов отключено");
}
if(preg_match('/updateConfigConnectionLink(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();


    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    $file_id = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();

    $rahgozar = $order['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    $response = getJson($server_id)->obj;
    if($inboundId == 0){
        foreach($response as $row){
            $clients = json_decode($row->settings)->clients;
            if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                $inboundRemark = $row->remark;
                $iId = $row->id;
                $port = $row->port;
                $protocol = $row->protocol;
                $netType = json_decode($row->streamSettings)->network;
                break;
            }
        }
    }else{
        foreach($response as $row){
            if($row->id == $inboundId) {
                $iId = $row->id;
                $inboundRemark = $row->remark;
                $port = $row->port;
                $protocol = $row->protocol;
                $netType = json_decode($row->streamSettings)->network;
                break;
            }
        }
    }

    if($botState['updateConnectionState'] == "robot"){
        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
        $stmt->bind_param("i", $file_id);
        $stmt->execute();
        $server_config = $stmt->get_result()->fetch_assoc();
        
        $netType = $file_detail['type'];
        $protocol = $file_detail['protocol'];
        $security = $server_config['security'];

        updateConfig($server_id, $iId, $protocol, $netType, $security, $rahgozar);
    }
    $vraylink = getConnectionLink($server_id, $uuid, $protocol, $remark, $port, $netType, $inboundId, $rahgozar, $customPath, $customPort, $customSni);
    
    $vray_link = json_encode($vraylink);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `link`=? WHERE `id`=?");
    $stmt->bind_param("si", $vray_link, $oid);
    $stmt->execute();
    $stmt->close();
    $keys = getOrderDetailKeys($from_id, $oid);
    editText($message_id, $keys['msg'], $keys['keyboard'],"HTML");
}
if(preg_match('/changAccountConnectionLink(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();


    $date = jdate("Y-m-d H:i",$order['date']);
    $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $inboundId = $order['inbound_id'];
    $acc_link = $order['link'];
    $server_id = $order['server_id'];
    $rahgozar = $order['rahgozar'];
    
    $file_id = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    $response = getJson($server_id)->obj;
    if($inboundId == 0){
        foreach($response as $row){
            $clients = json_decode($row->settings)->clients;
            if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                $port = $row->port;
                $protocol = $row->protocol;
                $netType = json_decode($row->streamSettings)->network;
                break;
            }
        }
        
        $update_response = renewInboundUuid($server_id, $uuid);
    }else{
        foreach($response as $row){
            if($row->id == $inboundId) {
                $port = $row->port; 
                $protocol = $row->protocol;
                $netType = json_decode($row->streamSettings)->network;
                break;
            }
        }
        $update_response = renewClientUuid($server_id, $inboundId, $uuid);
    }

    $newToken = RandomString(30);
    $newUuid = $update_response->newUuid;
    $vraylink = getConnectionLink($server_id, $newUuid, $protocol, $remark, $port, $netType, $inboundId, $rahgozar, $customPath, $customPort, $customSni);
    
    $vray_link = json_encode($vraylink);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `link`=?, `uuid` = ?, `token` = ? WHERE `id`=?");
    $stmt->bind_param("sssi", $vray_link, $newUuid, $newToken, $oid);
    $stmt->execute();
    $stmt->close();
    $keys = getOrderDetailKeys($from_id, $oid);
    editText($message_id, $keys['msg'], $keys['keyboard'],"HTML");
}
if(preg_match('/changeUserConfigState(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $order['userid'];
    $uuid = $order['uuid']??"0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    
    if($inboundId == 0){
        $update_response = changeInboundState($server_id, $uuid);
    }else{
        $update_response = changeClientState($server_id, $inboundId, $uuid);
    }
    
    if($update_response->success){
        alert($mainValues['please_wait_message']);
    
        $keys = getUserOrderDetailKeys($oid);
        editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
    }else sendMessage("В желаемой операции возникла проблема");
}

if(preg_match('/changeAccProtocol(\d+)_(\d+)_(.*)/', $data,$match)){
    $fid = $match[1];
    $oid = $match[2];
    $protocol = $match[3];

	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1"); 
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();


	if($respd){
		$respd = $respd->fetch_assoc(); 
		$stmt= $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $respd['catid']);
        $stmt->execute();
        $cadquery = $stmt->get_result();
        $stmt->close();


		if($cadquery) {
			$catname = $cadquery->fetch_assoc()['title'];
			$name = $catname." ".$respd['title'];
		}else $name = "$id";
		
	}else $name = "$id";

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();


    $date = jdate("Y-m-d H:i",$order['date']);
    $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $acc_link = $order['link'];
    $server_id = $order['server_id'];
    $price = $order['amount'];
    $rahgozar = $order['rahgozar'];
    $file_id = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    $response = getJson($server_id)->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $port = $row->port;
            $netType = json_decode($row->streamSettings)->network;
            $security = json_decode($row->streamSettings)->security;
            break;
        }
    }
    if($protocol == 'trojan') $netType = 'tcp';
    $uniqid = generateRandomString(42,$protocol); 
    $leftgb = round( ($total - $up - $down) / 1073741824, 2) . " GB"; 
    $update_response = editInbound($server_id, $uniqid, $uuid, $protocol, $netType, $security, $rahgozar);
    $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, 0, $rahgozar, $customPath, $customPort, $customSni);
    
    $vray_link = json_encode($vraylink);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `protocol`=?,`link`=?, `uuid` = ? WHERE `id`=?");
    $stmt->bind_param("sssi", $protocol, $vray_link, $uniqid, $oid);
    $stmt->execute();
    $stmt->close();
    $keys = getOrderDetailKeys($from_id, $oid);
    editText($message_id, $keys['msg'], $keys['keyboard'],"HTML");
}
if(preg_match('/^discountRenew(\d+)_(\d+)/',$userInfo['step'], $match) || preg_match('/renewAccount(\d+)/',$data,$match) && $text != $buttonValues['cancel']){
    if(preg_match('/^discountRenew/', $userInfo['step'])){
        $rowId = $match[2];
        
        $time = time();
        $stmt = $connection->prepare("SELECT * FROM `discounts` WHERE (`expire_date` > $time OR `expire_date` = 0) AND (`expire_count` > 0 OR `expire_count` = -1) AND `hash_id` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ?");
        $stmt->bind_param("i", $rowId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $hash_id = $payInfo['hash_id'];
        $afterDiscount = $payInfo['price'];
        $stmt->close();
        
        if($list->num_rows>0){
            $discountInfo = $list->fetch_assoc();
            $amount = $discountInfo['amount'];
            $type = $discountInfo['type'];
            $count = $discountInfo['expire_count'];
            $usedBy = !is_null($discountInfo['used_by'])?json_decode($discountInfo['used_by'],true):array();            
            
            $canUse = $discountInfo['can_use'];
            $userUsedCount = array_count_values($usedBy)[$from_id];
            if($canUse > $userUsedCount){
                $usedBy[] = $from_id;
                $encodeUsedBy = json_encode($usedBy);
                
                if ($count != -1) $query = "UPDATE `discounts` SET `expire_count` = `expire_count` - 1, `used_by` = ? WHERE `id` = ?";
                else $query = "UPDATE `discounts` SET `used_by` = ? WHERE `id` = ?";
    
                $stmt = $connection->prepare($query);
                $stmt->bind_param("si", $encodeUsedBy, $discountInfo['id']);
                $stmt->execute();
                $stmt->close();
                
                if($type == "percent"){
                    $discount = $afterDiscount * $amount / 100;
                    $afterDiscount -= $discount;
                    $discount = number_format($discount) . " токен";
                }else{
                    $afterDiscount -= $amount;
                    $discount = number_format($amount) . " токен";
                }
                if($afterDiscount < 0) $afterDiscount = 0;
                
                $stmt = $connection->prepare("UPDATE `pays` SET `price` = ? WHERE `id` = ?");
                $stmt->bind_param("ii", $afterDiscount, $rowId);
                $stmt->execute();
                $stmt->close();
                sendMessage(str_replace("AMOUNT", $discount, $mainValues['valid_discount_code']));
                $keys = json_encode(['inline_keyboard'=>[
                    [
                        ['text'=>"❤️", "callback_data"=>"wizwizch"]
                        ],
                    ]]);
                sendMessage(
                    str_replace(['USERID', 'USERNAME', "NAME", "AMOUNT", "DISCOUNTCODE"], [$from_id, $username, $first_name, $discount, $text], $mainValues['used_discount_code'])
                    ,$keys,null,$admin);
            }else sendMessage($mainValues['not_valid_discount_code']);
        }else sendMessage($mainValues['not_valid_discount_code']);
        setUser();
    }else delMessage();

    $oid = $match[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();
    if($order->num_rows == 0){
        delMessage();
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit();
    }
    $order = $order->fetch_assoc();
    $serverId = $order['server_id'];
    $fid = $order['fileid'];
    $agentBought = $order['agent_bought'];
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $respd['price'];
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$fid]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
    }
    if(!preg_match('/^discountRenew/', $userInfo['step'])){
        $hash_id = RandomString();
        $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'RENEW_ACCOUNT' AND `state` = 'pending'");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $stmt->close();
        
        $time = time();
        $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                    VALUES (?, ?, 'RENEW_ACCOUNT', ?, '0', '0', ?, ?, 'pending')");
        $stmt->bind_param("siiii", $hash_id, $from_id, $oid, $price, $time);
        $stmt->execute();
        $rowId = $stmt->insert_id;
        $stmt->close();
    }else $price = $afterDiscount;

    if($price == 0) $price = "Бесплатно";
    else $price .= " токен";
    $keyboard = array();
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => "💳 Сумма с карты на карту $price",  'callback_data' => "payRenewWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => "Оплата с остатком суммы $price",  'callback_data' => "payRenewWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    if(!preg_match('/^discountRenew/', $userInfo['step'])) $keyboard[] = [['text' => " 🎁 У вас есть код скидки? ",  'callback_data' => "haveDiscountRenew_" . $match[1] . "_" . $rowId]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];



    sendMessage("Пожалуйста, обновите свою учетную запись, используя один из способов ниже. :",json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/payRenewWithCartToCart(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $oid = $stmt->get_result()->fetch_assoc()['plan_id'];
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();
    if($order->num_rows == 0){
        delMessage();
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit();
    }
    
    setUser($data);
    delMessage();

    sendMessage(str_replace(['ACCOUNT-NUMBER', 'HOLDER-NAME'],[$paymentKeys['bankAccount'], $paymentKeys['holderName']], $mainValues['renew_ccount_cart_to_cart']),$cancelKey,"html");
    exit;
}
if(preg_match('/payRenewWithCartToCart(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    if(isset($update->message->photo)){
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $hash_id = $payInfo['hash_id'];
        $stmt->close();
        
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $stmt->close();
    

        
        $oid = $payInfo['plan_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $fid = $order['fileid'];
        $remark = $order['remark'];
        $uid = $order['userid'];
        $userName = $userInfo['username'];
        $uname = $userInfo['name'];
        
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $respd = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $price = $payInfo['price'];
        $volume = $respd['volume'];
        $days = $respd['days'];
        
        sendMessage($mainValues['renew_order_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        // notify admin
        
        $msg = str_replace(['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],['Карта за картой', $from_id, $username, $first_name, $price, $remark, $volume, $days], $mainValues['renew_account_request_message']);
    
        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "approveRenewAcc$hash_id"],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decRenewAcc$hash_id"]
                ]
            ]
        ]);
    
        sendPhoto($fileid, $msg,$keyboard, "HTML", $admin);
        setUser();
    }else{
        sendMessage($mainValues['please_send_only_image']);
    }
}
if(preg_match('/approveRenewAcc(.*)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $hash_id = $payInfo['hash_id'];
    $stmt->close();
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    
    $uid = $payInfo['user_id'];
    $oid = $payInfo['plan_id'];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $fid = $order['fileid'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $server_id = $order['server_id'];
    $inbound_id = $order['inbound_id'];
    $expire_date = $order['expire_date'];
    $expire_date = ($expire_date > $time) ? $expire_date : $time;
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $name = $respd['title'];
    $days = $respd['days'];
    $volume = $respd['volume'];
    $price = $payInfo['price'];


    unset($markup[count($markup)-1]);
    $markup[] = [['text'=>"✅",'callback_data'=>"wizwizch"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);


    editKeys($keys);

    
    if($inbound_id > 0)
        $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
    else
        $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
    
	if(is_null($response)){
		alert('🔻Техническая проблема с подключением к серверу. Пожалуйста, сообщите руководству',true);
		exit;
	}
	$stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
	$newExpire = $time + $days * 86400;
	$stmt->bind_param("ii", $newExpire, $oid);
	$stmt->execute();
	$stmt->close();
	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
	$stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
	$stmt->execute();
	$stmt->close();
    sendMessage(str_replace(["REMARK", "VOLUME", "DAYS"],[$remark, $volume, $days], $mainValues['renewed_config_to_user']), getMainKeys(),null,null);
    sendMessage("✅Услуга $remark успешно продлена.",null,null,$uid);
    exit;
}
if(preg_match('/decRenewAcc(.*)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $hash_id = $payInfo['hash_id'];
    $stmt->close();
    
    $uid = $payInfo['user_id'];
    $oid = $payInfo['plan_id'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $fid = $order['fileid'];
    $remark = $order['remark'];
    $server_id = $order['server_id'];
    $inbound_id = $order['inbound_id'];
    $expire_date = $order['expire_date'];
    $expire_date = ($expire_date > $time) ? $expire_date : $time;
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $name = $respd['title'];
    $days = $respd['days'];
    $volume = $respd['volume'];
    $price = $respd['price'];


    unset($markup[count($markup)-1]);
    $markup[] = [['text' => '❌', 'callback_data' => "dontsendanymore"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);

    editKeys($keys);
    sendMessage("😖|Продление услуги $remark отменено",null,null,$uid);
    exit;
}
if(preg_match('/payRenewWithWallet(.*)/', $data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $hash_id = $payInfo['hash_id'];
    $stmt->close();
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    $oid = $payInfo['plan_id'];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();

    if($order->num_rows == 0){
        delMessage();
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit();
    }
    $order = $order->fetch_assoc();
    
    $fid = $order['fileid'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $server_id = $order['server_id'];
    $inbound_id = $order['inbound_id'];
    $expire_date = $order['expire_date'];
    $expire_date = ($expire_date > $time) ? $expire_date : $time;
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $name = $respd['title'];
    $days = $respd['days'];
    $volume = $respd['volume'];
    $price = $payInfo['price'];

    $userwallet = $userInfo['wallet'];

    if($userwallet < $price) {
        $needamount = $price - $userwallet;
        alert("💡Баланс кошелька (".number_format($userwallet)." токен) недостаточно, дайте пожалуйста сумму ".number_format($needamount)." Зарядка токен ",true);
        exit;
    }

    if($inbound_id > 0)
        $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
    else
        $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");

	if(is_null($response)){
		alert('🔻Техническая проблема с подключением к серверу. Пожалуйста, сообщите руководству',true);
		exit;
	}
	$stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
	$newExpire = $time + $days * 86400;
	$stmt->bind_param("ii", $newExpire, $oid);
	$stmt->execute();
	$stmt->close();
	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
	$stmt->bind_param("iiisii", $from_id, $server_id, $inbound_id, $remark, $price, $time);
	$stmt->execute();
	$stmt->close();
	
	$stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
	$stmt->bind_param("ii", $price, $from_id);
	$stmt->execute();
	$stmt->close();
    editText($message_id, "✅Услуга $remark успешно продлена.",getMainKeys());
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"расширить 😍",'callback_data'=>"wizwizch"]
            ],
        ]]);
    $msg = str_replace(['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],['کیف پول', $from_id, $username, $first_name, $price, $remark, $volume, $days], $mainValues['renew_account_request_message']);

    sendMessage($msg, $keys,"html", $admin);
    exit;
}
if(preg_match('/switchLocation(.+)_(.+)_(.+)_(.+)/', $data,$match)){
    $order_id = $match[1];
    $server_id = $match[2];
    $leftgp = $match[3];
    $expire = $match[4]; 
    if($expire < time() or $leftgp <= 0) {
        alert("Ваша услуга отключена. Сначала продлите ее.",true);exit;
    }
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1 and ucount > 0 AND `id` != ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('На данный момент нет активных серверов для смены локаций.',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "switchServer{$sid}_{$order_id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];
    editText($message_id, ' 📍 Пожалуйста, выберите один из серверов, чтобы изменить текущее местоположение службы.👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if($data=="giftVolumeAndDay"){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('На данный момент нет активных серверов подарков.',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "giftToServer{$sid}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    editText($message_id, ' 📍 Пожалуйста, выберите один из серверов, чтобы подарить подарок👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/^giftToServer(\d+)/',$data,$match)){
    delMessage();
    sendMessage("Пожалуйста, введите актуальный срок действия подарка\nВведите 0, чтобы не добавлять время", $cancelKey);
    setUser('giftServerDay' . $match[1]);
}
if(preg_match('/^giftServerDay(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text >= 0){
            sendMessage("Пожалуйста, укажите размер подарка в мегабайтах\nВведите 0, чтобы не добавлять траффик");
            setUser('giftServerVolume' . $match[1] . "_" . $text);
        }else sendMessage("Введите число больше или равное 0");
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^giftServerVolume(\d+)_(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text >= 0){
            $stmt = $connection->prepare("INSERT INTO `gift_list` (`server_id`, `volume`, `day`) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $match[1], $text, $match[2]);
            $stmt->execute();
            $stmt->close();
            
            sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());

            setUser();
        }else sendMessage("Введите число больше или равное 0");
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/switchLocation(.+)_(.+)_(.+)_(.+)/', $data,$match)){
    $order_id = $match[1];
    $server_id = $match[2];
    $leftgp = $match[3];
    $expire = $match[4]; 
    if($expire < time() or $leftgp <= 0) {
        alert("Ваша услуга отключена. Сначала продлите ее.",true);exit;
    }
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1 and ucount > 0 AND `id` != ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('На данный момент нет активных серверов для смены локаций.',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "switchServer{$sid}_{$order_id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];
    editText($message_id, ' 📍 Пожалуйста, выберите один из серверов, чтобы изменить текущее местоположение службы.👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/switchServer(.+)_(.+)/',$data,$match)){
    $sid = $match[1];
    $oid = $match[2];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $fid = $order['fileid'];
    $protocol = $order['protocol'];
	$link = json_decode($order['link'])[0];
	
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid); 
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $flow = $file_detail['flow'] == "None"?"":$file_detail['flow'];
	
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $reality = $server_info['reality'];
    $serverType = $server_info['type'];

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $srv_remark = $stmt->get_result()->fetch_assoc()['remark'];


    if($botState['remark'] == "digits"){
        $rnd = rand(10000,99999);
        $newRemark = "{$srv_remark}-{$rnd}";
    }else{
        $rnd = rand(1111,99999);
        $newRemark = "{$srv_remark}-{$from_id}-{$rnd}";
    }
	
    if(preg_match('/vmess/',$link)){
        $link_info = json_decode(base64_decode(str_replace('vmess://','',$link)));
        $uniqid = $link_info->id;
        $port = $link_info->port;
        $netType = $link_info->net;
    }else{
        $link_info = parse_url($link);
        $panel_ip = $link_info['host'];
        $uniqid = $link_info['user'];
        $protocol = $link_info['scheme'];
        $port = $link_info['port'];
        $netType = explode('type=',$link_info['query'])[1]; 
        $netType = explode('&',$netType)[0];
    }

    if($inbound_id > 0) {
        $remove_response = deleteClient($server_id, $inbound_id, $uuid);
		if(is_null($remove_response)){
			alert('🔻Соединение с сервером не установлено. Пожалуйста, сообщите руководству',true);
			exit;
		}
        if($remove_response){
            $total = $remove_response['total'];
            $up = $remove_response['up'];
            $down = $remove_response['down'];
			$id_label = $protocol == 'trojan' ? 'password' : 'id';
			if($serverType == "sanaei" || $serverType == "alireza"){
			    if($reality == "true"){
                    $newArr = [
                      "$id_label" => $uniqid,
                      "email" => $newRemark,
                      "enable" => true,
                      "flow" => $flow,
                      "limitIp" => $remove_response['limitIp'],
                      "totalGB" => $total - $up - $down,
                      "expiryTime" => $remove_response['expiryTime'],
                      "subId" => RandomString(16)
                    ];			        
			    }else{
                    $newArr = [
                      "$id_label" => $uniqid,
                      "email" => $newRemark,
                      "enable" => true,
                      "limitIp" => $remove_response['limitIp'],
                      "totalGB" => $total - $up - $down,
                      "expiryTime" => $remove_response['expiryTime'],
                      "subId" => RandomString(16)
                    ];
			    }
			}else{
                $newArr = [
                  "$id_label" => $uniqid,
                  "flow" => $remove_response['flow'],
                  "email" => $newRremark,
                  "limitIp" => $remove_response['limitIp'],
                  "totalGB" => $total - $up - $down,
                  "expiryTime" => $remove_response['expiryTime']
                ];
			}
            
            $response = addInboundAccount($sid, '', $inbound_id, 1, $newRemark, 0, 1, $newArr); 
            if(is_null($response)){
                alert('🔻Соединение с сервером не установлено. Пожалуйста, сообщите руководству',true);
                exit;
            }
			if($response == "inbound not Found"){
                alert("🔻Линия (inbound) с идентификатором $inbound_id не найдена на этом сервере. Пожалуйста, сообщите руководству",true);
                exit;
            }
			if(!$response->success){
				alert('🔻Ошибка создания конфигурации. Пожалуйста, сообщите руководству',true);
				exit;
			}
			$vray_link = getConnectionLink($sid, $uniqid, $protocol, $newRemark, $port, $netType, $inbound_id);
			deleteClient($server_id, $inbound_id, $uuid, 1);
        }
    }else{
        $response = deleteInbound($server_id, $uuid);
		if(is_null($response)){
			alert('🔻Соединение с сервером не установлено. Пожалуйста, сообщите руководству',true);
			exit;
		}
        if($response){
            $res = addUser($sid, $response['uniqid'], $response['protocol'], $response['port'], $response['expiryTime'], $newRemark, $response['volume'] / 1073741824, $response['netType'], $response['security']);
            $vray_link = getConnectionLink($sid, $response['uniqid'], $response['protocol'], $newRemark, $response['port'], $response['netType'], $inbound_id);
            deleteInbound($server_id, $uuid, 1);
        }
    }
    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $stmt->close();

    $vray_link = json_encode($vray_link);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `server_id` = ?, `link`=?, `remark` = ? WHERE `id` = ?");
    $stmt->bind_param("issi", $sid, $vray_link, $newRemark, $oid);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $server_title = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ? AND `status` = 1 ORDER BY `id` DESC");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();
    
    $keyboard = [];
    while($cat = $orders->fetch_assoc()){
        $id = $cat['id'];
        $cremark = $cat['remark'];
        $keyboard[] = ['text' => "$cremark", 'callback_data' => "orderDetails$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];
    $msg = " 📍Местоположение службы $remark было изменено на $server_title с примечанием $newRemark.\n Нажмите на нее, чтобы просмотреть подробности.👇";
    
    editText($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    exit();
}
elseif(preg_match('/^deleteMyConfig(\d+)/',$data,$match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $remark = $order['remark'];

    editText($message_id, "Вы уверены, что хотите удалить конфигурацию $remark?",json_encode([
        'inline_keyboard' => [
            [['text'=>"ДА",'callback_data'=>"yesDeleteConfig" . $match[1]],['text'=>"НЕТ",'callback_data'=>"noDontDelete"]]
            ]
    ]));
}
elseif($data=="noDontDelete"){
    editText($message_id, "Запланированная операция была отменена",json_encode([
        'inline_keyboard' => [
            [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
            ]
    ]));
}
elseif(preg_match('/^yesDeleteConfig(\d+)/',$data,$match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $fileid = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param("i", $fileid);
    $stmt->execute();
    $planDetail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
	$volume = $planDetail['volume'];
	$days = $planDetail['days'];
	
	
    if($inbound_id > 0) $res = deleteClient($server_id, $inbound_id, $uuid, 1);
    else $res = deleteInbound($server_id, $uuid, 1);
    
    $leftMb = sumerize($res['total'] - $res['up'] - $res['down']);
    $expiryDay = $res['expiryTime'] != 0?
        floor(
            (substr($res['expiryTime'],0,-3)-time())/(60 * 60 * 24))
            :
            "نامحدود";
    if(is_numeric($expiryDay)){
        if($expiryDay<0) $expiryDay = 0;
    }

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $stmt->close();

    $vray_link = json_encode($vray_link);
    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $stmt->close();

    editText($message_id, "Конфигурация $remark успешно удалена",json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
                ]
        ]));
        
sendMessage("🔋|💰 удалить конфигурацию

Идентификатор пользователя: $from_id
Имя пользователя: $first_name
⚡️ Имя пользователя: $username
🎈 Имя сервиса: $remark
🔋 Объем услуги: $volume Гб
⏰ Продолжительность услуги: $дней.
❌ Оставшийся объем: $leftMb
📆 оставшийся день: $expiryDay дней
",null,"html", $admin);
    exit();
}
elseif(preg_match('/^delUserConfig(\d+)/',$data,$match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $remark = $order['remark'];

    editText($message_id, "Вы уверены, что хотите удалить конфигурацию $remark?",json_encode([
        'inline_keyboard' => [
            [['text'=>"ДА",'callback_data'=>"yesDeleteUserConfig" . $match[1]],['text'=>"НЕТ",'callback_data'=>"noDontDelete"]]
            ]
    ]));
}
elseif($data=="noDontDelete"){
    editText($message_id, "Запланированная операция была отменена",json_encode([
        'inline_keyboard' => [
            [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
            ]
    ]));
}
elseif(preg_match('/^yesDeleteUserConfig(\d+)/',$data,$match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $userId = $order['userid'];
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    
	
    if($inbound_id > 0) $res = deleteClient($server_id, $inbound_id, $uuid, 1);
    else $res = deleteInbound($server_id, $uuid, 1);
    

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $stmt->close();

    $vray_link = json_encode($vray_link);
    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $stmt->close();

    editText($message_id, "Конфигурация $remark успешно удалена",json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
                ]
        ]));
        
    exit();
}
if(preg_match('/increaseADay(.*)/', $data, $match)){
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];

    if($res->num_rows == 0){
        alert("В настоящее время планов по продлению периода обслуживания нет.");
        exit;
    }
    $keyboard = [];
    while ($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = number_format($cat['price']);
        if($agentBought == true){
            $discounts = json_decode($userInfo['discount_percent'],true);
            if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
            else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];
            $price -= floor($price * $discount / 100);
        }
        if($price == 0) $price = "Бесплатно";
        else $price .= " токен";
        $keyboard[] = ['text' => "на $title дней за $price токенов", 'callback_data' => "selectPlanDayIncrease{$match[1]}_$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
    editText($message_id, "Пожалуйста, выберите один из дополнительных планов :", json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/selectPlanDayIncrease(?<orderId>.+)_(?<dayId>.+)/',$data,$match)){
    $data = str_replace('selectPlanDayIncrease','',$data);
    $pid = $match['dayId'];
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $planprice = $res['price'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match['orderId']);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
    
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
        else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];

        $planprice -= floor($planprice * $discount / 100);
    }
    
    
    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` LIKE '%INCREASE_DAY%' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
    
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, ?, '0', '0', '0', ?, ?, 'pending')");
    $type = "INCREASE_DAY_$data";
    $stmt->bind_param("sisii", $hash_id, $from_id,$type, $planprice, $time);
    $stmt->execute();
    $stmt->close();

    
    $keyboard = array();
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payIncreaseDayWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payIncraseDayWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];
    editText($message_id, "Пожалуйста, завершите оплату одним из следующих способов. :",json_encode(['inline_keyboard' => $keyboard]));
}
if(preg_match('/payIncreaseDayWithCartToCart(.*)/',$data,$match)) {
    delMessage();
    setUser($data);
    sendMessage(str_replace(['ACCOUNT-NUMBER', 'HOLDER-NAME'],[$paymentKeys['bankAccount'], $paymentKeys['holderName']], $mainValues['renew_ccount_cart_to_cart']),$cancelKey,"html");

    exit;
}
if(preg_match('/payIncreaseDayWithCartToCart(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']){
    if(isset($update->message->photo)){
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result();
        $stmt->close();
        
        $payParam = $payInfo->fetch_assoc();
        $payType = $payParam['type'];
    
    
        preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType,$increaseInfo);
        $orderId = $increaseInfo[1];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $server_id = $orderInfo['server_id'];
        $inbound_id = $orderInfo['inbound_id'];
        $remark = $orderInfo['remark'];
        
        $planid = $increaseInfo[2];

        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $stmt->close();
    

        
        $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $price = $payParam['price'];
        $volume = $res['volume'];
    
        sendMessage($mainValues['renew_order_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
        // notify admin   
        $msg = str_replace(['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],[$volume, 'Время', $from_id, $username, $first_name, $price, $remark], $mainValues['increase_account_request_message']);
    
        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "approveIncreaseDay{$match[1]}"],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decIncreaseDay{$match[1]}"]
                ]
            ]
        ]);


        sendPhoto($fileid, $msg,$keyboard, "HTML", $admin);
        setUser();
    }else{ 
        sendMessage($mainValues['please_send_only_image']);
    }

}
if(preg_match('/approveIncreaseDay(.*)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];


    preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType,$increaseInfo);
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    $uuid = $orderInfo['uuid']??"0";
    
    $planid = $increaseInfo[2];

    
    $uid = $payParam['user_id'];
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $res['price'];
    $volume = $res['volume'];

    $acctxt = '';
    
    
    unset($markup[count($markup)-1]);

    
    if($inbound_id > 0) $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
    else $response = editInboundTraffic($server_id, $uuid, 0, $volume);
    
    if($response->success){
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
        $newVolume = $volume * 86400;
        $stmt->bind_param("is", $newVolume, $uuid);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
        $newVolume = $volume * 86400;
        $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
        $stmt->execute();
        $stmt->close();
        $markup[] = [['text' => '✅', 'callback_data' => "dontsendanymore"]];
        $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);
    
        editKeys($keys);
        sendMessage("✅$volume Дней, добавленных к продолжительности вашего обслуживания",null,null,$uid);
    }else {
        alert("Техническая проблема с сервером. Пожалуйста, проверьте работоспособность сервера",true);
        exit;
    }
}
if(preg_match('/payIncraseDayWithWallet(.*)/', $data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType, $increaseInfo);
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    $uuid = $orderInfo['uuid']??"0";
    
    $planid = $increaseInfo[2];



    
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $payParam['price'];
    $volume = $res['volume'];
    
    $userwallet = $userInfo['wallet'];

    if($userwallet < $price) {
        $needamount = $price - $userwallet;
        alert("💡Баланс кошелька (".number_format($userwallet)." токенов) недостаточно, дайте пожалуйста сумму ".number_format($needamount)." Зарядка токенов ",true);
        exit;
    }

    

    if($inbound_id > 0)
        $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
    else
        $response = editInboundTraffic($server_id, $uuid, 0, $volume);
        
    if($response->success){
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
        $newVolume = $volume * 86400;
        $stmt->bind_param("is", $newVolume, $uuid);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
        $newVolume = $volume * 86400;
        $stmt->bind_param("iiisii", $from_id, $server_id, $inbound_id, $remark, $price, $time);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $from_id);
        $stmt->execute();
        $stmt->close();
        editText($message_id, "✅$volume Дней, добавленных к продолжительности вашего обслуживания",getMainKeys());
        
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"Это заняло некоторое время 😁",'callback_data'=>"wizwizch"]
                ],
            ]]);
        sendMessage("🔋|💰 Увеличьте время с помощью (кошелька)

Идентификатор пользователя: $from_id
Имя пользователя: $first_name
⚡️ Имя пользователя: $username
🎈 Имя сервиса: $remark
⏰ Период увеличения: $volume день.
💰Цена: $price токен
⁮⁮ ⁮⁮
        ",$keys,"html", $admin);

        exit;
    }else {
        alert("По техническим причинам увеличить громкость невозможно. Пожалуйста, сообщите руководству или повторите тест через 5 минут.", true);
        exit;
    }
}
if(preg_match('/^increaseAVolume(.*)/', $data, $match)){
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
    
    if($res->num_rows==0){
        alert("Планов объемов на данный момент нет");
        exit;
    }
    $keyboard = [];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = number_format($cat['price']);
        if($agentBought == true){
            $discounts = json_decode($userInfo['discount_percent'],true);
            if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
            else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];
            $price -= floor($price * $discount / 100);
        }
        if($price == 0) $price = "Бесплатно";
        else $price .=  ' токен';
        
        $keyboard[] = ['text' => "$title Гб $price", 'callback_data' => "increaseVolumePlan{$match[1]}_{$id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>"Главная страница 🏘",'callback_data'=>"mainMenu"]];
    $res = editText($message_id, "Пожалуйста, выберите один из планов траффика :",json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/increaseVolumePlan(?<orderId>.+)_(?<volumeId>.+)/',$data,$match)){
    $data = str_replace('increaseVolumePlan','',$data);
    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $match['volumeId']);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $planprice = $res['price'];
    $plangb = $res['volume'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match['orderId']);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
 
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
        else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];
        
        $planprice -= floor($planprice * $discount / 100);
    }

    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` LIKE '%INCREASE_VOLUME%' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
    
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, ?, '0', '0', '0', ?, ?, 'pending')");
    $type = "INCREASE_VOLUME_$data";
    $stmt->bind_param("sisii", $hash_id, $from_id,$type, $planprice, $time);
    $stmt->execute();
    $stmt->close();
    
    $keyboard = array();
    
    if($planprice == 0) $planprice = ' Бесплатно';
    else $planprice = " " . number_format($planprice) . " токенов";
    
    
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'] . $planprice,  'callback_data' => "payIncreaseWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => "💰Оплата с баланса  " . $planprice,  'callback_data' => "payIncraseWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];
    editText($message_id, "Пожалуйста, завершите оплату одним из следующих способов. :",json_encode(['inline_keyboard' => $keyboard]));
}
if(preg_match('/payIncreaseWithCartToCart(.*)/',$data)) {
    setUser($data);
    delMessage();
    
    sendMessage(str_replace(['ACCOUNT-NUMBER', 'HOLDER-NAME'],[$paymentKeys['bankAccount'], $paymentKeys['holderName']], $mainValues['renew_ccount_cart_to_cart']),$cancelKey,"html");
    exit;
}
if(preg_match('/payIncreaseWithCartToCart(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    if(isset($update->message->photo)){
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result();
        $stmt->close();
        
        $payParam = $payInfo->fetch_assoc();
        $payType = $payParam['type'];
    
    
        preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo);
        $orderId = $increaseInfo[1];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $server_id = $orderInfo['server_id'];
        $inbound_id = $orderInfo['inbound_id'];
        $remark = $orderInfo['remark'];
        
        $planid = $increaseInfo[2];
    
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'sent' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $stmt->close();
    
        $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $price = $payParam['price'];
        $volume = $res['volume'];
        $state = str_replace('payIncreaseWithCartToCart','',$userInfo['step']);
        sendMessage($mainValues['renew_order_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
        // notify admin

        $msg = str_replace(['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],[$volume, 'حجم', $from_id, $username, $first_name, $price, $remark], $mainValues['increase_account_request_message']);

         $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "approveIncreaseVolume{$match[1]}"],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decIncreaseVolume{$match[1]}"]
                ]
            ]
        ]);

        sendPhoto($fileid, $msg,$keyboard, "HTML", $admin);
        setUser();
    }else{
        sendMessage($mainValues['please_send_only_image']);
    }
}
if(preg_match('/approveIncreaseVolume(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();


    preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo);
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    $uuid = $orderInfo['uuid']??"0";
    $planid = $increaseInfo[2];

    $uid = $payParam['user_id'];
    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i",$planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $res['price'];
    $volume = $res['volume'];

    $acctxt = '';
    
    
    if($inbound_id > 0) $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0);
    else $response = editInboundTraffic($server_id, $uuid, $volume, 0);
    
    if($response->success){
        $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $stmt->close();
        unset($markup[count($markup)-1]);
        $markup[] = [['text' => '✅', 'callback_data' => "dontsendanymore"]];
        $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);
    
        editKeys($keys);
        sendMessage("✅$volume Гб добавлен к объему вашего сервиса",null,null,$uid);
    }else {
        alert("Техническая проблема с сервером. Пожалуйста, проверьте работоспособность сервера",true);
        exit;
    }
}
if(preg_match('/decIncreaseVolume(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];


    preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo);
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    
    $planid = $increaseInfo[2];


    $uid = $payParam['user_id'];
    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i",$planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $res['price'];
    $volume = $res['volume'];

    $acctxt = '';
    editKeys(json_encode(['inline_keyboard'=>[
		    [['text'=>"Отменено ❌",'callback_data'=>"wizwizch"]]
		    ]]));
    
    sendMessage("увеличение траффика на $volume Гб по подписке $remark отменен.",null,null,$uid);
}
if(preg_match('/decIncreaseDay(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];


    preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType, $increaseInfo);
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    
    $planid = $increaseInfo[2];


    $uid = $payParam['user_id'];
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i",$planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $res['price'];
    $volume = $res['volume'];

    $acctxt = '';
    editKeys(json_encode(['inline_keyboard'=>[
		    [['text'=>"Отменено ❌",'callback_data'=>"wizwizch"]]
		    ]]));
    
    sendMessage("Продление времени $volume дней подписка $remark отменено",null,null,$uid);
}
if(preg_match('/payIncraseWithWallet(.*)/', $data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'sent')");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();


    preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo);
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    $uuid = $orderInfo['uuid']??"0";
    
    $planid = $increaseInfo[2];


    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $payParam['price'];
    $volume = $res['volume'];
    
    $userwallet = $userInfo['wallet'];

    if($userwallet < $price) {
        $needamount = $price - $userwallet;
        alert("💡Баланс кошелька (".number_format($userwallet)." токенов) недостаточно, дайте пожалуйста сумму ".number_format($needamount)." Пополните токены ",true);
        exit;
    }

    if($inbound_id > 0)
        $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0);
    else
        $response = editInboundTraffic($server_id, $uuid, $volume, 0);
        
    if($response->success){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $from_id);
        $stmt->execute();
        $stmt->close();
        $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $stmt->close();
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"Наконец-то один том увеличился 😁",'callback_data'=>"wizwizch"]
                ],
            ]]);
        sendMessage("🔋|💰 увеличение объема с помощью (кошелька)

Идентификатор пользователя: $from_id
Имя пользователя: $first_name
⚡️ Имя пользователя: $username
🎈 Имя сервиса: $remark
⏰ Период увеличения: $volume Гб
💰Цена: $price токен
⁮⁮ ⁮⁮
        ",$keys,"html", $admin);
        editText($message_id, "✅$volume Гб добавлен к объему вашего сервиса",getMainKeys());exit;
        

    }else {
        alert("По техническим причинам увеличить громкость невозможно. Пожалуйста, сообщите руководству или повторите тест через 5 минут.",true);
        exit;
    }
}
if($data == 'cantEditTrojan'){
    alert("Протокол Trojan имеет только тип сети TCP");
    exit;
}
if(($data=='categoriesSetting' || preg_match('/^nextCategoryPage(\d+)/',$data,$match)) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(isset($match[1])) $keys = getCategoriesKeys($match[1]);
    else $keys = getCategoriesKeys();
    
    editText($message_id,"☑️ Управление категориями:", $keys);
}
if($data=='addNewCategory' and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    setUser($data);
    delMessage();
    $stmt = $connection->prepare("DELETE FROM `server_categories` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();


    $sql = "INSERT INTO `server_categories` VALUES (NULL, 0, '', 0,2,0);";
    $stmt = $connection->prepare($sql);
    $stmt->execute();
    $stmt->close();


    $msg = '▪️Введите название категории:';
    sendMessage($msg,$cancelKey);
    exit;
}
if(preg_match('/^addNewCategory/',$userInfo['step']) and $text!=$buttonValues['cancel']){
    $step = checkStep('server_categories');
    if($step==2 and $text!=$buttonValues['cancel'] ){
        
        $stmt = $connection->prepare("UPDATE `server_categories` SET `title`=?,`step`=4,`active`=1 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();


        $msg = 'Я зарегистрировал для вас новую категорию 🙂☑️';
        sendMessage($msg,$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getCategoriesKeys());
    }
}
if(preg_match('/^wizwizcategorydelete(\d+)_(\d+)/',$data, $match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("Я удалил категорию для тебя ☹️☑️");
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `active`=1 AND `parent`=0");
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();

    $keys = getCategoriesKeys($match[2]);
    editText($message_id,"☑️ Управление категориями:", $keys);
}
if(preg_match('/^wizwizcategoryedit/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("〽️ Выберите новое название для категории:",$cancelKey);exit;
}
if(preg_match('/wizwizcategoryedit(\d+)_(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_categories` SET `title`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("Я успешно изменил его для вас ☺️☑️");
    setUser();
    
    sendMessage("☑️ Управление категориями:", getCategoriesKeys($match[2]));
}
if(($data=='serversSetting' || preg_match('/^nextServerPage(\d+)/',$data,$match)) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(isset($match[1])) $keys = getServerListKeys($match[1]);
    else $keys = getServerListKeys();
    
    editText($message_id,"☑️ Управление сервером:",$keys);
}
if(preg_match('/^toggleServerState(\d+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_info` SET `state` = IF(`state` = 0,1,0) WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $cats= $stmt->get_result();
    $stmt->close();
    
    alert("Статус сервера успешно изменен");
    
    $keys = getServerListKeys($match[2]);
    editText($message_id,"☑️ Управление сервером:",$keys);
}
if(preg_match('/^showServerSettings(\d+)_(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getServerConfigKeys($match[1], $match[2]);
    editText($message_id,"☑️ Управление сервером: $cname",$keys);
}
if(preg_match('/^changesServerIp(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $serverIp= $stmt->get_result()->fetch_assoc()['ip']??"اطلاعاتی یافت نشد";
    $stmt->close();
    
    delMessage();
    sendMessage("Список текущих IP-адресов: \n$serverIp\nПожалуйста, пришлите новые IP-адреса отдельными строками.\n\nЧтобы очистить текст /empty входить",$cancelKey,null,null,null);
    setUser($data);
    exit();
}
if(preg_match('/^changesServerIp(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_config` SET `ip` = ? WHERE `id`=?");
    if($text == "/empty") $text = "";
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();
    
    $keys = getServerConfigKeys($match[1]);
    sendMessage("☑️ Управление сервером: $cname",$keys);
    exit();
}
if(preg_match('/^changePortType(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_config` SET `port_type` = IF(`port_type` = 'auto', 'random', 'auto') WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("Характер желаемого порта сервера был успешно изменен.");
    
    $keys = getServerConfigKeys($match[1]);
    editText($message_id,"☑️ Управление сервером: $cname",$keys);
    
    exit();
}
if(preg_match('/^changeRealityState(\d+)/',$data,$match)){
    $stmt = $connection->prepare("UPDATE `server_config` SET `reality` = IF(`reality` = 'true', 'false', 'true') WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $keys = getServerConfigKeys($match[1]);
    editText($message_id,"☑️ Управление сервером: $cname",$keys);
    
    exit();
}
if(preg_match('/^changeServerType(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id,"🔰 Важное примечание: (обновите панель x-ui до последней версии)

❤️ Если вы используете панель Senai, выберите тип панели (Senai).
🧡 Если вы используете панель Alireza, выберите тип панели (Alireza).
💚 Если вы используете панель Niduka, выберите тип панели (простая).
💙 Если вы используете китайскую панель, выберите тип панели (простая).
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
📣 Обязательно выбирайте тип панели, иначе это будет для вас проблемой!
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
",json_encode(['inline_keyboard'=>[
        [['text'=>"простой",'callback_data'=>"chhangeServerTypenormal_" . $match[1]],['text'=>"Сенай",'callback_data'=>"chhangeServerTypesanaei_" . $match[1]]],
        [['text'=>"Алиреза",'callback_data'=>"chhangeServerTypealireza_" . $match[1]]]
        ]]));
    exit();
}
if(preg_match('/^chhangeServerType(\w+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    alert($mainValues['saved_successfuly']);
    $stmt = $connection->prepare("UPDATE `server_config` SET `type` = ? WHERE `id`=?");
    $stmt->bind_param("si",$match[1], $match[2]);
    $stmt->execute();
    $stmt->close();
    
    $keys = getServerConfigKeys($match[2]);
    editText($message_id, "☑️ Управление сервером: $cname",$keys);
}
if($data=='addNewServer' and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    delMessage();
    setUser('addserverName');
    sendMessage("Начальная ступень: 
▪️Выберите имя для вашего сервера:",$cancelKey);
    exit();
}
if($userInfo['step'] == 'addserverName' and $text != $buttonValues['cancel']) {
	sendMessage('вторая стадия: 
▪️Укажите количество возможностей создания конфигурации для вашего сервера (число подойдет)');
    $data = array();
    $data['title'] = $text;

    setUser('addServerUCount' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerUCount(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']) {
    $data = json_decode($match[1],true);
    $data['ucount'] = $text;

    sendMessage("третий уровень: 
▪️Выберите имя (примечание) для конфигурации: (на английском языке без пробелов)
");
    setUser('addServerRemark' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerRemark(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']) {
    $data = json_decode($match[1], true);
    $data['remark'] = $text;

    sendMessage("Четвертый этап:
▪️Пожалуйста, выберите смайлик флага для своего сервера.:");
    setUser('addServerFlag' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerFlag(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']) {
    $data = json_decode($match[1],true);
    $data['flag'] = $text;

    sendMessage("Пятый шаг:

▪️Пожалуйста, введите адрес панели x-ui, как показано ниже.:

❕https://yourdomain.com:54321
❕https://yourdomain.com:54321/path
❗️http://125.12.12.36:54321
❗️http://125.12.12.36:54321/path

Если желаемый сервер имеет домен и SSL, используйте пример (❕).
Если желаемый сервер с IP и без SSL, используйте пример (❗️).
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
");
    setUser('addServerPanelUrl' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerPanelUrl(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']) {
    $data = json_decode($match[1],true);
    $data['panel_url'] = $text;
    setUser('addServerIp' . json_encode($data,JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 Пожалуйста, введите IP-адрес или туннельный домен панели.:

Образец: 
91.257.142.14
sub.domain.com
❗️Если вы хотите настроить несколько доменов или IP, необходимо написать ниже и отправить роботу:
    \n\n🔻Чтобы оставить текст пустым введите /empty");
    exit();
}
if(preg_match('/^addServerIp(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']) {
    $data = json_decode($match[1],true);
    $data['panel_ip'] = $text;
    setUser('addServerSni' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 пожалуйста sni Войдите в панель\n\n🔻Чтобы оставить текст пустым/empty входить");
    exit();
}
if(preg_match('/^addServerSni(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']) {
    $data = json_decode($match[1],true);
    $data['sni'] = $text;
    setUser('addServerHeaderType' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 Если вы используете тип заголовка, введите http:\n\n🔻Чтобы оставить текст пустым введите /empty");
    exit();
}
if(preg_match('/^addServerHeaderType(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']) {
    $data = json_decode($match[1],true);
    $data['header_type'] = $text;
    setUser('addServerRequestHeader' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅Если вы используете заголовок, введите адрес Host:test.com и замените test.com желаемым адресом.:\n\n🔻Чтобы оставить текст пустым введите /empty");
    exit();
}
if(preg_match('/^addServerRequestHeader(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']) {
    $data = json_decode($match[1],true);
    $data['request_header'] = $text;
    setUser('addServerResponseHeader' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 Пожалуйста, введите заголовок ответа панели\n\n🔻Чтобы оставить текст пустым введите /empty");
    exit();
}
if(preg_match('/^addServerResponseHeader(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']) {
    $data = json_decode($match[1],true);
    $data['response_header'] = $text;
    setUser('addServerSecurity' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 Пожалуйста, войдите в панель безопасности

⚠️ Примечание. Чтобы использовать tls или xtls, введите слово tls или xtls в противном случае. 👇
\n🔻Чтобы оставить текст пустым введите /empty");
exit();
}
if(preg_match('/^addServerSecurity(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']) {
    $data = json_decode($match[1],true);
    $data['security'] = $text;
    setUser('addServerTlsSetting' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage("
    🔅 Введите панель настроек tls|xtls. Чтобы оставить текст пустым, введите /empty.

⚠️ Пожалуйста, внимательно выполняйте настройки сертификата, например:
▫️serverName: yourdomain
▫️certificateFile: /root/cert.crt
▫️keyFile: /root/private.key
\n
"
        .'<b>tls setting:</b> <code>{"serverName": "","certificates": [{"certificateFile": "","keyFile": ""}]}</code>' . "\n"
        .'<b>xtls setting:</b> <code>{"serverName": "","certificates": [{"certificateFile": "","keyFile": ""}],"alpn": []}</code>', null, "HTML");

    exit();
}
if(preg_match('/^addServerTlsSetting(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']) {
    $data = json_decode($match[1],true);
    $data['tls_setting'] = $text;
    setUser('addServerPanelUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "Шестой шаг:
Пожалуйста, войдите в панель пользователя:");

    exit();
}
if(preg_match('/^addServerPanelUser(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']) {
    $data = json_decode($match[1],true);
    $data['panel_user'] = $text;
    setUser('addServerPanePassword' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "Седьмой этап: 
▪️Пожалуйста, введите пароль панели:");
exit();
}
if(preg_match('/^addServerPanePassword(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    sendMessage("⏳ Вход в учетную запись ...");
    $data = json_decode($match[1],true);
    $title = $data['title'];
    $ucount = $data['ucount'];
    $remark = $data['remark'];
    $flag = $data['flag'];

    $panel_url = $data['panel_url'];
    $ip = $data['panel_ip']!="/empty"?$data['panel_ip']:"";
    $sni = $data['sni']!="/empty"?$data['sni']:"";
    $header_type = $data['header_type']!="/empty"?$data['header_type']:"none";
    $request_header = $data['request_header']!="/empty"?$data['request_header']:"";
    $response_header = $data['response_header']!="/empty"?$data['response_header']:"";
    $security = $data['security']!="/empty"?$data['security']:"none";
    $tlsSettings = $data['tls_setting']!="/empty"?$data['tls_setting']:"";
    $serverName = $data['panel_user'];
    $serverPass = $text;
    $loginUrl = $panel_url . '/login';
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $loginUrl);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_COOKIEJAR, dirname(__FILE__) . '/tempCookie.txt');
    $loginResponse = json_decode(curl_exec($ch),true);
    curl_close($ch);
    if(!$loginResponse['success']){
        setUser('addServerPanelUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
        sendMessage( "
⚠️ Вы столкнулись с ошибкой ! 

Чтобы решить эту проблему, нажмите на ссылку ниже и внимательно послушайте голос. 👇

⛔️🔗 https://t.me/wizwizch/186

Введите имя пользователя панели еще раз:
⁮⁮ ⁮⁮
        ");
        exit();
    }
    unlink("tempCookie.txt");
    $stmt = $connection->prepare("INSERT INTO `server_info` (`title`, `ucount`, `remark`, `flag`, `active`)
                                                    VALUES (?,?,?,?,1)");
    $stmt->bind_param("siss", $title, $ucount, $remark, $flag);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();

    $stmt = $connection->prepare("INSERT INTO `server_config` (`id`, `panel_url`, `ip`, `sni`, `header_type`, `request_header`, `response_header`, `security`, `tlsSettings`, `username`, `password`)
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssssssss", $rowId, $panel_url, $ip, $sni, $header_type, $request_header, $response_header, $security, $tlsSettings, $serverName, $serverPass);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();

    sendMessage(" поздравления; Вы зарегистрировали свой сервер 🥹",$removeKeyboard);

        sendMessage("🔰 Важное примечание: (обновите панель x-ui до последней версии)

❤️ Если вы используете панель Senai, выберите тип панели (Senai).
🧡 Если вы используете панель Alireza, выберите тип панели (Alireza).
💚 Если вы используете панель Niduka, выберите тип панели (простая).
💙 Если вы используете китайскую панель, выберите тип панели (простая).
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
📣 Обязательно выбирайте тип панели, иначе это будет для вас проблемой!
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
    ",json_encode(['inline_keyboard'=>[
            [['text'=>"простой",'callback_data'=>"chhangeServerTypenormal_" . $rowId],['text'=>"Сенай",'callback_data'=>"chhangeServerTypesanaei_" . $rowId]],
            [['text'=>"Алиреза",'callback_data'=>"chhangeServerTypealireza_" . $rowId]]
            ]]));
    setUser();
    exit();
}
if(preg_match('/^changesServerLoginInfo(\d+)/',$data,$match)) {
    delMessage();
    setUser($data);
    sendMessage( "▪️Пожалуйста, введите адрес панели:",$cancelKey);
}
if(preg_match('/^changesServerLoginInfo(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel']) {
    $data = array();
    $data['rowId'] = $match[1];
    $data['panel_url'] = $text;
    setUser('editServerPaneUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "▪️Пожалуйста, войдите в панель пользователя:",$cancelKey);
    exit();
}
if(preg_match('/^editServerPaneUser(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel']) {
    $data = json_decode($match[1],true);
    $data['panel_user'] = $text;
    setUser('editServerPanePassword' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "▪️Пожалуйста, введите пароль панели:");
    exit();
}
if(preg_match('/^editServerPanePassword(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    sendMessage("⏳ Вход в учетную запись ...");
    $data = json_decode($match[1],true);

    $rowId = $data['rowId'];
    $panel_url = $data['panel_url'];
    $serverName = $data['panel_user'];
    $serverPass = $text;
    $loginUrl = $panel_url . '/login';
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $loginUrl);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_COOKIEJAR, dirname(__FILE__) . '/tempCookie.txt');
    $loginResponse = json_decode(curl_exec($ch),true);
    curl_close($ch);
    if(!$loginResponse['success']) sendMessage( "Введенная вами информация неверна 😂");
    else{
        $stmt = $connection->prepare("UPDATE `server_config` SET `panel_url` = ?, `username` = ?, `password` = ? WHERE `id` = ?");
        $stmt->bind_param("sssi", $panel_url, $serverName, $serverPass, $rowId);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("Данные для входа на сервер успешно изменены",$removeKeyboard);
    }
    unlink("tempCookie.txt");

    $keys = getServerConfigKeys($rowId);
    sendMessage('☑️ Управление сервером:',$keys);
    setUser();
}
if(preg_match('/^wizwizdeleteserver(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $connection->prepare("DELETE FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("🙂 Почему ты удалил сервер? ...");
    

    $keys = getServerListKeys();
    if($keys == null) editText($message_id,"Ничего не найдено");
    else editText($message_id,"☑️ Управление сервером:",$keys);
}
if(preg_match('/^editServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel']){
    switch($match[1]){
        case "Name":
            $txt ="title";
            break;
        case "Max":
            $txt = "volume";
            break; 
        case "Remark":
            $txt ="remark";
            break;
        case "Flag":
            $txt = "flag"; 
            break;
        default:
            $txt = str_replace("_", " ", $match[1]);
            $end = "Чтобы очистить текст /empty входить";
            break;
    }
    delMessage();
    sendMessage("🔘|пожалуйста " . $txt . " Введите новый" . $end,$cancelKey);
    setUser($data);
    exit();
}
if(preg_match('/^editServer(\D+)(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel']){
    switch($match[1]){
        case "Name":
            $txt ="title";
            break;
        case "Max":
            $txt = "ucount";
            break;
        case "Remark":
            $txt ="remark";
            break;
        case "Flag":
            $txt = "flag";
            break;
        default:
            $txt = $match[1];
            break;
    }
    
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_info` SET `$txt` IS NULL WHERE `id`=?");
        $stmt->bind_param("i", $match[2]);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_info` SET `$txt`=? WHERE `id`=?");
        $stmt->bind_param("si",$text, $match[2]);
        $stmt->execute();
        $stmt->close();
    }
    
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();
    
    $keys = getServerConfigKeys($match[2]);
    sendMessage("مدیریت سرور $cname",$keys);
    exit();
}
if(preg_match('/^editsServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel']){
    $txt = str_replace("_", " ", $match[1]);
    delMessage();
    sendMessage("🔘|пожалуйста " . $txt . " Введите новый\nЧтобы очистить текст /empty войдите",$cancelKey);
    setUser($data);
    exit();
}
if(preg_match('/^editsServer(\D+)(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        if($match[1] == "header_type" || $match[1] == "security"){
            $stmt = $connection->prepare("UPDATE `server_config` SET `{$match[1]}` = 'none' WHERE `id`=?");
            $stmt->bind_param("i", $match[2]);
        }else{
            $stmt = $connection->prepare("UPDATE `server_config` SET `{$match[1]}` = '' WHERE `id`=?");
            $stmt->bind_param("i", $match[2]);
        }
    }else{
        if($match[1] == "header_type" && $text != "http" && $text != "none"){
            sendMessage("Для типа заголовка разрешено только none или http.");
            exit();
        }
        elseif($match[1] == "security" && $text != "tls" && $text != "none" && $text != "xtls"){
            sendMessage("Для типа безопасности разрешены только tls или xtls или ничего.");
            exit();
        }
        $stmt = $connection->prepare("UPDATE `server_config` SET `{$match[1]}`=? WHERE `id`=?");
        $stmt->bind_param("si",$text, $match[2]);
    }
    $stmt->execute();
    $stmt->close();
    
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();
    
    $keys = getServerConfigKeys($match[2]);
    sendMessage("Управление сервером $cname",$keys);
    exit();
}
if(preg_match('/^editServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel']){
    switch($match[1]){
        case "Name":
            $txt ="title";
            break;
        case "Max":
            $txt = "volume";
            break;
        case "Remark":
            $txt ="remark";
            break;
        case "Flag":
            $txt = "flag";
            break;
    }
    delMessage();
    sendMessage("🔘|пожалуйста " . $txt . " Введите новый",$cancelKey);
    setUser($data);
}
if(preg_match('/^editServer(\D+)(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel']){
    switch($match[1]){
        case "Name":
            $txt ="title";
            break;
        case "Max":
            $txt = "ucount";
            break;
        case "Remark":
            $txt ="remark";
            break;
        case "Flag":
            $txt = "flag";
            break;
    }
    
    $stmt = $connection->prepare("UPDATE `server_info` SET `$txt`=? WHERE `id`=?");
    $stmt->bind_param("si",$text, $match[2]);
    $stmt->execute();
    $stmt->close();
    
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();
    
    $keys = getServerConfigKeys($match[2]);
    sendMessage("Управление сервером $cname",$keys);
}
if($data=="discount_codes" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id,"Управление кодами скидок",getDiscountCodeKeys());
}
if($data=="addDiscountCode" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🔘|Пожалуйста, введите сумму скидки\nДля процента введите знак % рядом с числом, иначе сумма скидки будет рассчитана в токенах.",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addDiscountCode" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $dInfo = array();
    $dInfo['type'] = 'amount';
    if(strpos($text, "%")) $dInfo['type'] = 'percent';
    $text = trim(str_replace("%", "", $text));
    if(is_numeric($text)){
        $dInfo['amount'] = $text;
        setUser("addDiscountDate" . json_encode($dInfo,JSON_UNESCAPED_UNICODE));
        sendMessage("🔘|Пожалуйста, введите текущий срок действия этой скидки.\nВведите 0, чтобы получить неограниченный срок действия.");
    }else sendMessage("🔘|Пожалуйста, присылайте только цифры или проценты.");
}
if(preg_match('/^addDiscountDate(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $dInfo = json_decode($match[1],true);
        $dInfo['date'] = $text != 0?time() + ($text * 24 * 60 * 60):0;
        
        setUser("addDiscountCount" . json_encode($dInfo,JSON_UNESCAPED_UNICODE));
        sendMessage("🔘|Пожалуйста, введите количество раз, которое вы можете использовать эту скидку.\nВведите 0, чтобы получить неограниченное количество раз.");
    }else sendMessage("🔘|Пожалуйста, пришлите только номер");
}
if(preg_match('/^addDiscountCount(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){ 
        $dInfo = json_decode($match[1],true);
        $dInfo['count'] = $text>0?$text:-1;
        
        setUser('addDiscountCanUse' . json_encode($dInfo,JSON_UNESCAPED_UNICODE));
        sendMessage("Пожалуйста, введите количество использований для каждого пользователя");
    }else sendMessage("🔘|Пожалуйста, пришлите только номер");
}
if(preg_match('/^addDiscountCanUse(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){ 
        $dInfo = json_decode($match[1],true);
        $dInfo['can_use'] = $text>0?$text:-1;
         
        $hashId = RandomString();
        
        $stmt = $connection->prepare("INSERT INTO `discounts` (`hash_id`, `type`, `amount`, `expire_date`, `expire_count`, `can_use`)
                                        VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("ssiiii", $hashId, $dInfo['type'], $dInfo['amount'], $dInfo['date'], $dInfo['count'], $dInfo['can_use']);
        $stmt->execute();
        $stmt->close();
        sendMessage("Новый код скидки (<code>$hashId</code>) Он был успешно построен",$removeKeyboard,"HTML");
        setUser();
        sendMessage("Управление кодами скидок",getDiscountCodeKeys());
    }else sendMessage("🔘|Пожалуйста, пришлите только номер");
}
if(preg_match('/^delDiscount(\d+)/',$data,$match)){
    $stmt = $connection->prepare("DELETE FROM `discounts` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert("Желаемый код скидки был успешно удален.");
    editText($message_id,"Управление кодами скидок",getDiscountCodeKeys());
}
if(preg_match('/^copyHash(.*)/',$data,$match)){
    sendMessage("<code>" . $match[1] . "</code>",null,"HTML");
}
if($data == "managePanel" and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    
    setUser();
    $msg = "
👤 Дорогой мой, добро пожаловать в раздел управления 
🤌 Вы можете добавить и изменить здесь все, что вам нужно. $first_name  если у тебя есть доход от продажи робота, поддержи меня, чтобы проект всегда обновлялся. !

🚪 /start
";
    editText($message_id, $msg, getAdminKeys());
}
if($data == 'reciveApplications') {
    $stmt = $connection->prepare("SELECT * FROM `needed_sofwares` WHERE `status`=1");
    $stmt->execute();
    $respd= $stmt->get_result();
    $stmt->close();

    $keyboard = []; 
    while($file =  $respd->fetch_assoc()){ 
        $link = $file['link'];
        $title = $file['title'];
        $keyboard[] = ['text' => "$title", 'url' => $link];
    }
    $keyboard[] = ['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"];
    $keyboard = array_chunk($keyboard,1); 
    editText($message_id, "Вы можете легко скачать все файлы (бесплатно).
📌 Вы можете следовать нашему руководству по подключению к официальному каналу, а также загрузить необходимые программы для каждой операционной системы с помощью кнопок ниже.

✅ Наше предложение — программа V2Ray, поскольку с ней легко работать и она применима для всех операционных систем, вы можете перейти в раздел нужной операционной системы и получить ссылку на скачивание.
", json_encode(['inline_keyboard'=>$keyboard]));
}
if ($text == $buttonValues['cancel']) {
    setUser();
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();

    sendMessage($mainValues['waiting_message'], $removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getMainKeys());
}
?>
