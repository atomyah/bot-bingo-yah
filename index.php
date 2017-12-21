<?php
require_once __DIR__ .'/vendor/autoload.php';
require __DIR__ . '/functions.php';

define('TABLE_NAME_SHEETS', 'sheets');
define('TABLE_NAME_ROOMS', 'rooms');

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
      // ビンゴのボールを１個ひく
    } else if (substr($event->getText(), 4) == 'proceed') {
      if(getRoomIdOfUser($event->getUserId()) === PDO::PARAM_NULL) {
        replyTextMessage($bot, $event->getReplyToken(), 'ルームに入っていません');
      } else if(getSheetOfUser($event->getUserId()) === PDO::PARAM_NULL) {
        replyTextMessage($bot, $event->getReplyToken(), 'シートが配布されていません。まずビンゴ開始を押してください。');
      } else {
        // ユーザーがそのルームでビンゴを開始したユーザーでない場合
        if(getHostOfRoom(getRoomIdOfUser($event->getUserId())) != $event->getUserId()) {
          replyTextMessage($bot, $event->getReplyToken(), '進行できるのはホストだけです。');
        } else {
          // ボールをひく
          proceedBingo($bot, $event->getUserId());
        }        
      }
      // ビンゴ終了確認ダイアログ
    } else if (substr($event->getText(), 4) == 'end_confirm') {
      if(getRoomIdOfUser($event->getUserId()) === PDO::PARAM_NULL) {
        replyTextMessage($bot, $event->getReplyToken(), 'ルームに入っておりません');
      } else {
        if(getHostOfRoom(getRoomIdOfUser($event->getUserId())) != $event->getUserId()) {
          replyTextMessage($bot, $event->getReplyToken(), 'ゲーム終了できるのはゲームを開始したホストのみです。');
        } else {
          replyConfirmTemplate($bot, $event->getReplyToken(), '本当に終了しますか？データはすべて失われます', '本当に終了しますか？データはすべて失われます',
                  new \LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder('はい', 'cmd_end'),
                  new \LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder('いいえ', 'cmd_cancel'));
        }
      }
    } else if(substr($event->getText(), 4) == 'end') {
      endBingo($bot, $event->getUserId());
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
  
  // roomsテーブルの初期化
  $sqlInsertRoom = 'insert into ' . TABLE_NAME_ROOMS . ' (roomid, balls, userid) values (?, ?, pgp_sym_encrypt(?, \'' . getenv('DB_ENCRYPT_PASS') . '\'))';
  $sthInsertRoom = $dbh->prepare($sqlInsertRoom);
  //０は中心。最初からあいてる
  $sthInsertRoom->execute(array($roomId, json_encode([0]), $userId));
  
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
      $imagemapMessageBuilder = new \LINE\LINEBot\MessageBuilder\ImagemapMessageBuilder (
          'https://' . $_SERVER['HTTP_HOST'] . '/sheet/' . urlencode($row['sheet']) . '/' . urlencode(json_encode(getBallsOfRoom(getRoomIdOfUser($userId)))) . '/' . uniqid(),
          'シート',
          new LINE\LINEBot\MessageBuilder\Imagemap\BaseSizeBuilder(1040, 1040), $actionsArray);
      $builder = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
      $builder->add(new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($text)); // ここの$textには$newBallが入る。74とか
      $builder->add($imagemapMessageBuilder);
      // ビンゴが成立している場合
      if(getIsUserHasBingo($row['userid'])) {
        //スタンプとテキストを追加
        $builder->add(new \LINE\LINEBot\MessageBuilder\StickerMessageBuilder(1, 134));
        $builder->add(new \LINE\LINEBot\MessageBuilder\TextMessageBuilder('ビンゴ！名乗りでて景品をもらってね！'));
      }
      $bot->pushMessage($row['userid'], $builder);
    }
}


// ビンゴを開始したユーザーのユーザIDを取得
function getHostOfRoom($roomId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\') as userid from ' . TABLE_NAME_ROOMS . ' where roomid = ?';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($roomId));
  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return $row['userid'];
  }
}


// ボールをひく
function proceedBingo($bot, $userId) {
  $roomId = getRoomIdOfUser($userId);
  
  $dbh = dbConnection::getConnection();
  $sql = 'select balls from ' . TABLE_NAME_ROOMS . ' where roomid = ?';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($roomId));
  if ($row = $sth->fetch()) {
    // [3][45][10][74][9][22]...というような文字列を、$ballsArray配列にデコード
    $ballArray = json_decode($row['balls']);
    //when balls are pulled all...
    if(count($ballArray) == 75) {
      $bot->pushMessage($userId, new \LINE\LINEBot\MessageBuilder\TextMessageBuilder('もうボールはありません。')
              );
    return;
    }
    // 重複しないボールがでるまで引く
    $newBall = 0;
    do {
      $newBall = rand(1, 75);
    } while(in_array($newBall, $ballArray));
    array_push($ballArray, $newBall);
    
    // ルームのボール情報テーブルを更新
    $sqlUpdateBall = 'update ' . TABLE_NAME_ROOMS . ' set balls = ? where roomid = ?';
    $sthUpdateBall = $dbh->prepare($sqlUpdateBall);
    $sthUpdateBall->execute(array(json_encode($ballArray), $roomId));
    
    // すべてのユーザーに送信
    pushSheetToUser($bot, $userId, $newBall);
  }
}



// ルームのボール情報を獲得
function getBallsOfRoom($roomId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select balls from ' . TABLE_NAME_ROOMS . ' where roomid = ?';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($roomId));
  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return json_decode($row['balls']);
  }
}



// ユーザーのシートがビンゴ成立しているかを調べる
function getIsUserHasBingo($userId) {
  $roomId = getRoomIdOfUser($userId);
  $balls = getBallsOfRoom($roomId);
  $sheet = getSheetOfUser($userId);
  
  // すでに引かれているボールに一致すれば-1を代入
  foreach ($sheet as &$col) {
    foreach ($col as &$num) {
      if(in_array($num, $balls)) {
        $num = -1;
      }
    }
  }
  
  // 縦か横の５マスの合計が-5ならビンゴ
  for($i = 0; $i < 5; $i++) {
    if(array_sum($sheet[$i]) == -5 || $sheet[0][$i] + $sheet[1][$i] + $sheet[2][$i] + $sheet[3][$i] + $sheet[4][$i] == -5) {
      return TRUE;
    } 
  }

  // 斜めの合計が-5ならビンゴ
  if($sheet[0][0] + $sheet[1][1] + $sheet[2][2] + $sheet[3][3] + $sheet[4][4] == -5 || 
          $sheet[0][4] + $sheet[1][3] + $sheet[2][2] + $sheet[3][1] + $sheet[4][0] == -5 ) {
    return TRUE;
  }
  
  return FALSE;
}


// ビンゴ終了
function endBingo($bot, $userId) {
  $roomId = getRoomIdOfUser($userId);
  
  $dbh = dbConnection::getConnection();
  $sql = 'select pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\') as userid, sheet from ' . TABLE_NAME_SHEETS . ' where roomid = ?';
  $sth = $dbh->prepare($sql);
  $sth->execute(array(getRoomIdOfUser($userId)));
  // 各ユーザーにメッセージを送信
  foreach ($sth->fetchAll() as $row) {
    $bot->pushMesssage($row['userid'], new \LINE\LINEBot\MessageBuilder\TextMessageBuilder('ビンゴ終了。退出しました。'));
  }
  
  // ユーザーを削除
  $sqlDeleteUser = 'delete from ' . TABLE_NAME_SHEETS . ' where roomid = ?';
  $sthDeleteUser = $dbh->prepare($sqlDeleteUser);
  $sthDeleteUser->execute(array($roomId));
  
  // ルームを削除
  $sqlDeleteRoom = 'delete from ' .TABLE_NAME_ROOMS .' where roomid = ?';
  $sthDeleteRoom = $dbh->prepare($sqlDeleteRoom);
  $sthDeleteRoom->execute(array($roomId));
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
