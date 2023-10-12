<?php
/**
 * Сборщик открытых данных для главной страницы RUVTuber Space
 */

// Определения всяких функций

/**
 * Получить XML-ссылку для канала YouTube
 * @url https://stackoverflow.com/a/54788908
 * @param string $url Ссылка на канал/пользователя (старая)/плейлист
 * @param bool $return_id_only Вернуть только идентификатор сущности
 * @return string Ссылка XML, либо идентификатор сущности
 */
function getYouTubeXMLUrl( $url, $return_id_only = false ) {
    $xml_youtube_url_base = 'https://www.youtube.com/feeds/videos.xml';
    $preg_entities        = [
        'channel_id'  => '\/channel\/(([^\/])+?)$', //match YouTube channel ID from url
        'user'        => '\/user\/(([^\/])+?)$', //match YouTube user from url
        'playlist_id' => '\/playlist\?list=(([^\/])+?)$',  //match YouTube playlist ID from url
    ];
    foreach ( $preg_entities as $key => $preg_entity ) {
        if ( preg_match( '/' . $preg_entity . '/', $url, $matches ) ) {
            if ( isset( $matches[1] ) ) {
                if( $return_id_only === false ) {
                    return $xml_youtube_url_base . '?' . $key . '=' . $matches[1];
                } else {
                    return [
                        'type' => $key,
                        'id' => $matches[1],
                    ];
                }
            }
        }
    }
}

// Установка временной зоны
date_default_timezone_set('Europe/Moscow');

// Определить константы
define('VK_CLIENT_ID', getenv('VK_CLIENT_ID'));
if(getenv('VK_TOKEN')) {
    define('VK_ACCESS_TOKEN', getenv('VK_TOKEN'));
} else {
    define('VK_ACCESS_TOKEN', getenv('VK_SERVICE_TOKEN'));
}
define('GS_URL', getenv('GS_URL'));
define('YT_API_KEY', getenv('YT_API_KEY'));

// Проверка на целостность окружения
if(!VK_ACCESS_TOKEN && !VK_CLIENT_ID && !GS_URL) {
    echo 'Некорректно настроено окружение', PHP_EOL;
    exit(1);
}

// Проверить на наличие композера
if(!file_exists(__DIR__.'/vendor/autoload.php')) {
    echo 'Composer не установлен', PHP_EOL;
    exit(1);
}

// Папка с данными виртуальных ютуберов
define('VTUBERS_DIR', __DIR__.'/upload/vtubers');

if(!is_dir(__DIR__.'/upload')) mkdir(__DIR__.'/upload');
if(!is_dir(VTUBERS_DIR)) mkdir(VTUBERS_DIR);

// Включение бибилотек
require_once(__DIR__.'/vendor/autoload.php');

// Получение данных из Google Spreadsheets
// name, name_variant, youtube, twitch, vk_group
$GSDATA = json_decode(file_get_contents(GS_URL));

// Омагад да это же база данных в оперативной памяти мухахаха
$db = new PDO('sqlite::memory:');
$db->exec("CREATE TABLE 'vtubers'
    (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        name_variant TEXT,
        youtube TEXT,
        twitch TEXT,
        vk_group TEXT
    )
");

// Первичный прогон
foreach ($GSDATA as $key => $value) {
    $id = md5($value[0]);
    $vtuber = array_merge(array($id), $value);
    if(trim($value['youtube']) == '') $value['youtube'] = null;
    if(trim($value['twitch']) == '') $value['twitch'] = null;
    if(trim($value['vk_group']) == '') $value['vk_group'] = null;
    $sth = $db->prepare("INSERT OR IGNORE INTO 'vtubers' (id, name, name_variant, youtube, twitch, vk_group) VALUES (?, ?, ?, ?, ?, ?)");
    $sth->execute($vtuber);
    // Создать папку
    if(!is_dir(VTUBERS_DIR.'/'.$id)) mkdir(VTUBERS_DIR.'/'.$id);
}

// Подключение клиента
$vk = new VK\Client\VKApiClient();

// Авторизация Twitch
function twitchToken($client_id, $client_secret) {
    $ch = curl_init('https://id.twitch.tv/oauth2/token');
    $data = array(
      "client_secret" => $client_secret,
      "client_id" => $client_id,
      "grant_type" => "client_credentials"
    );
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $json = curl_exec($ch);
    curl_close($ch);
    $ret = json_decode($json);
    return $ret->access_token;
}
$twitchToken = twitchToken(getenv('TWITCH_CLIENT_ID'), getenv('TWITCH_CLIENT_SECRET'));

// Подготовка массивов для сбора данных
$twitch = array();
$youtube = array();

// Вторичный прогон
$vtubers = $db->query('SELECT * FROM vtubers ORDER BY name');
// Плюнуть JSON в корень
$vtuber_list = $vtubers->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(VTUBERS_DIR.'/index.json', json_encode($vtuber_list, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_NUMERIC_CHECK));
foreach ($vtuber_list as $vtuber_key => $vtuber) {
    echo 'Рассматривается ', $vtuber['name'], PHP_EOL;
    // Определить рабочую папку
    $vtuber_folder = VTUBERS_DIR.'/'.$vtuber['id'];
    // Выкинуть все пустые значения
    $vtuber = array_filter((array)$vtuber);
    // Сбросить сведения
    file_put_contents($vtuber_folder.'/info.json', json_encode($vtuber, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_NUMERIC_CHECK));

    // Отдельные виды обработки

    // Паблик ВКонтакте
    if (isset($vtuber['vk_group']) && !empty((string)$vtuber['vk_group'])) {
        $group_id;
        preg_match('/https:\/\/vk.com\/(.*)/', $vtuber['vk_group'], $group_id);
        $group_id = $group_id[1];
        try {
            $group = $vk->groups()->getById(VK_ACCESS_TOKEN, [
                'group_id' => $group_id,
                'fields' => 'cover'
            ])[0];
            $group_filename = $vtuber_folder.'/vk.json';
            $group_json = json_encode($group, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_NUMERIC_CHECK);
            file_put_contents($group_filename, $group_json);
            // Скачать аватар группы
            if(isset($group['photo_200']))
                file_put_contents($vtuber_folder.'/vk_icon.jpg', file_get_contents($group['photo_200']));
            elseif(isset($group['photo_100']))
                file_put_contents($vtuber_folder.'/vk_icon.jpg', file_get_contents($group['photo_100']));
            elseif(isset($group['photo_50']))
                file_put_contents($vtuber_folder.'/vk_icon.jpg', file_get_contents($group['photo_50']));
            // Скачать обложку группы
            if(isset($group['cover']) && isset($group['cover']['images'])) {
                $idx = count($group['cover']['images'])-1;
                file_put_contents($vtuber_folder.'/vk_cover.jpg', file_get_contents($group['cover']['images'][$idx]['url']));
            }
            // Очистка памяти
            unset($group, $group_id, $group_filename, $group_json);
            copy($vtuber_folder.'/vk_icon.jpg', $vtuber_folder.'/main_icon.jpg');
        }
        catch (Exception $e) {
            echo ($e->getMessage()), PHP_EOL;
            sleep(1);
        }
    }

    // Канал YouTube
    if (isset($vtuber['youtube']) && !empty((string)$vtuber['youtube'])) {
        $feed_url = getYouTubeXMLUrl($vtuber['youtube']);
        $feed = @file_get_contents($feed_url);
        if($feed = !empty(trim($feed))) {
            file_put_contents($vtuber_folder.'/youtube.xml', file_get_contents($feed_url));
            $url = 'https://www.googleapis.com/youtube/v3/channels?part=snippet,brandingSettings&id='.getYouTubeXMLUrl($vtuber['youtube'], true)['id'].'&key='.YT_API_KEY;
            $json = file_get_contents($url);
            file_put_contents($vtuber_folder.'/youtube.json', $json);
            $channel = json_decode($json);
            // Скачать аватар канала
            if(isset($channel->items[0]->snippet->thumbnails->high))
                file_put_contents($vtuber_folder.'/youtube_icon.jpg', file_get_contents($channel->items[0]->snippet->thumbnails->high->url));
            elseif(isset($channel->items[0]->snippet->thumbnails->medium))
                file_put_contents($vtuber_folder.'/youtube_icon.jpg', file_get_contents($channel->items[0]->snippet->thumbnails->medium->url));
            else
                file_put_contents($vtuber_folder.'/youtube_icon.jpg', file_get_contents($channel->items[0]->snippet->thumbnails->default->url));
            // Скачать обложку канала
            if(isset($channel->items[0]->brandingSettings->image->bannerExternalUrl))
                file_put_contents($vtuber_folder.'/youtube_cover.jpg', file_get_contents($channel->items[0]->brandingSettings->image->bannerExternalUrl));
            unset($url, $json, $channel);
            copy($vtuber_folder.'/youtube_icon.jpg', $vtuber_folder.'/main_icon.jpg');
        } 
        unset($feed_url, $feed);
    }

    // Канал Twitch
    if (isset($vtuber['twitch']) && !empty((string)$vtuber['twitch'])) {
        $twitch_login;
        preg_match('/https:\/\/www.twitch.tv\/(.*)/', $vtuber['twitch'], $twitch_login);
        $twitch_login = $twitch_login[1];
        $ch = curl_init('https://api.twitch.tv/helix/users?login='.$twitch_login);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer '.$twitchToken,
            'Client-Id: '.getenv('TWITCH_CLIENT_ID')
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $json = curl_exec($ch);
        curl_close($ch);
        $response = json_decode($json);
        if(isset($response->data[0])) {
            $user = $response->data[0];
            if(isset($user->email)) unset($user->email);
            $json = json_encode($user, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_NUMERIC_CHECK);
            file_put_contents($vtuber_folder.'/twitch.json', $json);
            // Скачать обложку и аватарку
            if(isset($user->profile_image_url) && !empty($user->profile_image_url)) {
                file_put_contents($vtuber_folder.'/twitch_icon.jpg', file_get_contents($user->profile_image_url));
            }
            if(isset($user->offline_image_url) && !empty($user->offline_image_url)) {
                file_put_contents($vtuber_folder.'/twitch_cover.jpg', file_get_contents($user->offline_image_url));
            }
        }
        copy($vtuber_folder.'/twitch_icon.jpg', $vtuber_folder.'/main_icon.jpg');
    }
}
