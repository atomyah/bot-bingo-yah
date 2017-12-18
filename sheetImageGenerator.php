<?php

// Composerでインストールしたライブラリを一括読み込み
require_once __DIR__ . '/vendor/autoload.php';
//合成サイズのベース
define('GD_BASE_SIZE', 700);

//空のシート画像を作成
$destinationImage = imagecreatefrompng('imgs/bingo_bg.png');

//シート情報を受け取り配列に変換
$sheet = json_decode(urlencode($_REQUEST['sheet']));
//引かれたボールの情報を受け取り配列に変換
$balls = json_decode(urlencode($_REQUEST['balls']));

//数字とボールの配列を比較し穴を合成
for($i = 0; $i < count($sheet); $i++) {
  $col = $sheet[$i];
  for($j = 0; $j < count($col); $j++) {
      if($col[$j] != 0) {
	  // 数字の画像を取得
      $numImage = imagecreatefrompng('imgs/' . str_pad($col[$j], 2, 0, STR_PAD_LEFT) . '.png');
      imagecopy($destinationImage, $numImage, 15 + (int)($i * 134), 116 + (int)($j * 114), 0, 0, 134, 114);
        imagedestroy($numImage);
    }
      // 数字とボールの配列を比較し穴を合成
      if(in_array($col[$j], $balls)) {
      $holeImage = imagecreatefrompng('imgs/hole.png');
      imagecopy($destinationImage, $holeImage, 15 + (int)($i * 134), 116 + (int)($j * 114), 0, 0, 134, 114);
        imagedestroy($holeImage);
    }
  }
}


//リクエストされているサイズを取得
$size = $_REQUEST['size'];
//ベースサイズと同じならなにもしない
if($size == GD_BASE_SIZE) {
  $out = $destinationImage;
} else {
  //リクエストされたサイズの空の画像を生成
  $out = imagecreatetruecolor($size, $size);
  // リサイズしながら合成
  imagecopyresampled($out, $destinationImage, 0, 0, 0, 0, $size, $size, GD_BASE_SIZE, GD_BASE_SIZE);
}


//出力のバッファリングを有効に
ob_start();
//バッファに出力
imagepng($out, null, 9);
//バッファから画像を取得
$content = ob_get_contents();
//バッファを消去しバッファをオフ
ob_end_clean();


//出力タイプを指定
header('Content-type: image/png');
//画像を出力
echo $content;

?>
