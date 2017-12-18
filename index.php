<?php
require_once __DIR__ .'/vendor/autoload.php';
require __DIR__ . '/functions.php';

define('TABLE_NAME_SHEETS', 'sheets');

$httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));

$bot = new \LINE\LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);

$signature = $_SERVER['HTTP_' . \LINE\LINEBot\Constant\HTTPHeader::LINE_SIGNATURE];

try {
  $events = $bot->parseEventRequest(file_get_contents('php://input'), $signature);
} catch (\LINE\LINEBot\Exception\InvalidSignatureException $e) {
  error_log('ParseEventRequest failed. InvalidSignatureException => '. var_export($e, TRUE));
} catch (\LINE\LINEBot\Exception\UnknownEventTypeException $e) {
  error_log('ParseEventRequest failed. UnknownEventTypeException => '. var_export($e, TRUE));
} catch (\LINE\LINEBot\Exception\UnknownMessageTypeException $e) {
  error_log('ParseEventRequest failed. UnknownMessageTypeException => '. var_export($e, TRUE));
} catch (\LINE\LINEBot\Exception\InvalidEventRequestException $e) {
  error_log('ParseEventRequest failed. InvalidEventRequestException => '. var_export($e, TRUE));
}


foreach ($events as $event) {
  if (!($event instanceof \LINE\LINEBot\Event\MessageEvent)) {
    error_log('not message event has come');
    continue;
  }
  if (!($event instanceof \LINE\LINEBot\Event\MessageEvent\TextMessage)) {
    error_log('not text message has come');
    continue;
  }

  //リッチコンテンツがタップされたとき
  if(substr($event->getText(), 0, 4) == 'cmd_') {
    //ルーム作成
    if(substr($event->getText(), 4) == 'newroom') {
      //ユーザーが未入室の場合
      if(getRoomIdOfUser($event->getUserId()) === PDO::PARAM_NULL) {
        //ルームを作成し、入室後ルームIDを取得
        $roomId = createRoomAndGetRoomId($event->getUserId());
        //ルームIDをユーザーに返信
        replyMultiMessage($bot, $event->getReplyToken(), 
                new LINE\LINEBot\MessageBuilder\TextMessageBuilder('ルームを作成し入室しました。ルームIDは'),
                new LINE\LINEBot\MessageBuilder\TextMessageBuilder($roomId),
                new LINE\LINEBot\MessageBuilder\TextMessageBuilder('です。'));
      // すでに入室している時        
      } else {
        replyTextMessage($bot, $event->getReplyToken(), '既に入室済みです。');
      }
      // 入室
    } else if(substr($event->getText(), 4) == 'enter') {
      // ユーザーが未入室の時
      if(getRoomIdOfUser($event->getUserId()) === PDO::PARAM_NULL) {
        replyTextMessage($bot, $event->getReplyToken(), 'ルームIDを入力してください。');
      } else {
        replyTextMessage($bot, $event->getReplyToken(), '入室済みです。');
      }
      // 退室確認ダイアログ
    } else if(substr($event->getText(), 4) == 'leave_confirm') {
      replyConfirmTemplate($bot, $event->getReplyToken(), '本当に退出しますか？', '本当に退出しますか？',
              new LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder('はい', 'cmd_leave'),
              new LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder('いいえ', 'cancel'));
      // 退室
    } else if(substr($event->getText(), 4) == 'leave') {
      if(getRoomIdOfUser($event->getUserId()) !== PDO::PARAM_NULL) {
        leaveRoom($event->getUserId());
        replyTextMessage($bot, $event->getReplyToken(), '退出しました');
      } else {
        replyTextMessage($bot, $event->getReplyToken(), 'ルームに入っていません');
      }
      // ルーム内でビンゴスタート
    } else if(substr($event->getText(), 4) == 'start') {
      if(getRoomIdOfUser($event->getUserId()) === PDO::PARAM_NULL) {
        replyTextMessage($bot, $event->getReplyToken(), 'ルームに入っていません');
      } else if(getSheetOfUser($event->getUserId()) !== PDO::PARAM_NULL) {
        replyTextMessage($bot, $event->getReplyToken(), 'すでに配布されています');
      } else {
       // シートを準備
       prepareSheets($bot, $event->getUserId());
      }
    }
    continue;
  }
  
  // リッチコンテンツ以外の時（ルームIDが入力されたとき)
  if(getRoomIdOfUser($event->getUserId()) === PDO::PARAM_NULL) {
    //入室
    $roomId = enterRoomAndGetRoomId($event->getUserId(), $event->getText());
    //成功時
    if($roomId !== PDO::PARAM_NULL) {
      replyTextMessage($bot, $event->getReplyToken(), 'ルームID' . $roomId . 'に入室しました');
    } else {
      replyTextMessage($bot, $event->getReplyToken(), 'そのルームIDは存在しません。');
    }
  }
}



// ユーザーIDからルームIDを取得
function getRoomIdOfUser($userId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select roomid from ' . TABLE_NAME_SHEETS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId));
  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return $row['roomid'];
  }
}


// ルームを作成し入室後ルームIDを返す
function createRoomAndGetRoomId($userId) {
  $roomId = uniqid();
  $dbh = dbConnection::getConnection();
  $sql = 'insert into ' . TABLE_NAME_SHEETS . ' (userid, sheet, roomid) values (pgp_sym_encrypt(?, \'' . getenv('DB_ENCRYPT_PASS') . '\'), ?, ?) ';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId, PDO::PARAM_NULL, $roomId));
  return $roomId;
}



//入室しルームIDを返す
function enterRoomAndGetRoomId($userId, $roomId) {
  $dbh = dbConnection::getConnection();
  $sql = 'insert into ' . TABLE_NAME_SHEETS . ' (userid, sheet, roomid) SELECT pgp_sym_encrypt(?, \'' . getenv('DB_ENCRYPT_PASS') .'\'), ?, ?
    where exists(select roomid from ' . TABLE_NAME_SHEETS . ' where roomid = ?) returning roomid';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId, PDO::PARAM_NULL, $roomId, $roomId));
  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return $row['roomid'];
  }
}


//退出
function leaveRoom($userId) {
  $dbh = dbConnection::getConnection();
  $sql = 'delete from ' . TABLE_NAME_SHEETS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId));
}


//ユーザーIDからシートを取得
function getSheetOfUser($userId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select sheet from ' . TABLE_NAME_SHEETS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId));
  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return json_decode($row['sheet']);
  }
}


// 各ユーザにシートを割当て
function prepareSheets($bot, $userId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\') as userid from ' . TABLE_NAME_SHEETS . ' where roomid = ?';
  $sth = $dbh->prepare($sql);
  $sth->execute(array(getRoomIdOfUser($userId)));
  foreach ($sth->fetchAll() as $row) {
    $sheetArray = array();
    
    for($i = 0; $i < 5; $i++) {
      // 各列内でランダム（１列目だったら１〜１５の範囲の数をランダムに５つピックアップ）
      $numArray = range(($i * 15) + 1, ($i * 15) + 1 + 14);
      shuffle($numArray);
      array_push($sheetArray, array_slice($numArray, 0, 5));
    }
    // 中央マスは０を上書き
    $sheetArray[2][2] = 0;
    // アップデート
    updateUserSheet($row['userid'], $sheetArray);
  }
  //すべてのユーザーにシートのImagemapを送信
  pushSheetToUser($bot, $userId, 'ビンゴ開始！');
}


// ユーザーのシートをアップデート
function updateUserSheet($userId, $sheet) {
  $dbh = dbConnection::getConnection();
  $sql = 'update ' . TABLE_NAME_SHEETS . ' set sheet = ? where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array(json_encode($sheet), $userId));
}


  //すべてのユーザーにシートのImagemapを送信
  function pushSheetToUser($bot, $userId, $text) {
    $dbh = dbConnection::getConnection();
    $sql = 'select pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\') as userid, sheet from ' . TABLE_NAME_SHEETS . ' where roomid = ?';
    $sth = $dbh->prepare($sql);
    $sth->execute(array(getRoomIdOfUser($userId)));
    
    $actionsArray = array();
    array_push($actionsArray, new \LINE\LINEBot\ImagemapActionBuilder\ImagemapMessageActionBuilder('-', 
            new LINE\LINEBot\ImagemapActionBuilder\AreaBuilder(0, 0, 1, 1)));
    
    //ユーザーひとりづつ処理
    foreach ($sth->fetchAll() as $row) {
      $imagemapMessageBuilder = new \LINE\LINEBot\MessageBuilder\
              ImagemapMessageBuilder('https://' . $_SERVER['HTTP_HOST'] . '/sheet/' . 
              urlencode($row['sheet']) . '/' . 
              urlencode(json_encode([0])) . '/' . uniqid(), 'シート',
              new LINE\LINEBot\MessageBuilder\Imagemap\BaseSizeBuilder(1040, 1040), $actionsArray);
      $builder = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
      $builder->add(new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($text));
      $builder->add($imagemapMessageBuilder);
      $bot->pushMessage($row['userid'], $builder);
    }
  }




  //DB接続用クラス
  class dbConnection {
    protected static $db;
    
    private function __construct() {
      try {
          $url = parse_url(getenv('DATABASE_URL'));
          $dsn = sprintf('pgsql:host=%s;dbname=%s', $url['host'], substr($url['path'], 1));
          self::$db = new PDO($dsn, $url['user'], $url['pass']);
          self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      } catch (PDOException $e) {
          echo 'Connection Error: ' . $e->getMessage();
      }
    }
    
    public static function getConnection() {
      if (!self::$db) {
        new dbConnection();
      }
      return self::$db;
    }
  }
?>
