<?php
/**
 * Сборщик открытых данных для главной страницы RUVTuber Space
 */

// Определения всяких функций



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
define('TWITCH_TOKEN', twitchToken(getenv('TWITCH_CLIENT_ID'), getenv('TWITCH_CLIENT_SECRET')));

/**
 * Cведения о канале
 */
class ChannelInfo {
    /**
     * @var string Списочный идентификатор
     */
    public $id;

    /**
     * @var string Название канала
     */
    public $name;

    /**
     * @var string Описание канала
     */
    public $description;

    /**
     * @var string Адрес канала
     */
    public $url;

    /**
     * @var bool Флаг наличия аватарки
     */
    public $icon;

    /**
     * @var bool Флаг наличия обложки
     */
    public $cover;

    /**
     * @var string Тип канала
     */
    private $type;

    /**
     * @var string Папка канала
     */
    private $folder;

    /**
     * Нет ничего проще конструктора
     * @param string $id Идентификатор канала
     * @param string $type Тип канала
     */
    public function __construct($id, $url, $type) {
        $this->id = trim($id);
        $this->url = trim($url);
        $this->type = trim($type);
        $this->folder = VTUBERS_DIR.'/'.$this->id;
        switch ($this->type) {
            case 'youtube': $this->youtube(); break;
            case 'twitch': $this->twitch(); break;
            case 'vk': $this->vk(); break;
            // Умолчания не дано
            default: return false; break;
        }
        // Установить флаг в зависимости от наличия файла
        if(file_exists("{$this->folder}/{$this->type}_icon.jpg")) $this->icon = true;
        if(file_exists("{$this->folder}/{$this->type}_cover.jpg")) $this->cover = true;
    }

    /**
     * Получить XML-ссылку для канала YouTube
     * @url https://stackoverflow.com/a/54788908
     * @param string $url Ссылка на канал/пользователя (старая)/плейлист
     * @param bool $return_id_only Вернуть только идентификатор сущности
     * @return string Ссылка XML, либо идентификатор сущности
     */
    private function getYouTubeXMLUrl($url, $return_id_only = false) {
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
    /**
     * Обработчик ютуба
     */
    private function youtube() {
        $feed_url = $this->getYouTubeXMLUrl($this->url);
        // Получить ленту видео
        $feed = @file_get_contents($feed_url);
        // На "нет" и суда нет
        if(!$feed) return false;
        // Запись в файл
        file_put_contents($this->folder.'/youtube.xml', $feed);
        // Получить сведения о канале
        $json = file_get_contents('https://www.googleapis.com/youtube/v3/channels?part=snippet,brandingSettings&id='.$this->getYouTubeXMLUrl($this->url, true)['id'].'&key='.YT_API_KEY);
        // Не работаем с пустотой
        if(!$json) return false;
        // Записать сведения о канале
        file_put_contents($this->folder.'/youtube.json', $json);
        $channel = json_decode($json);
        // Название канала
        $this->name = $channel->items[0]->snippet->title;
        $this->description = $channel->items[0]->snippet->description;
        // Скачать самый большой аватар канала
        if(isset($channel->items[0]->snippet->thumbnails->high))
            file_put_contents($this->folder.'/youtube_icon.jpg', file_get_contents($channel->items[0]->snippet->thumbnails->high->url));
        elseif(isset($channel->items[0]->snippet->thumbnails->medium))
            file_put_contents($this->folder.'/youtube_icon.jpg', file_get_contents($channel->items[0]->snippet->thumbnails->medium->url));
        elseif(isset($channel->items[0]->snippet->thumbnails->default))
            file_put_contents($this->folder.'/youtube_icon.jpg', file_get_contents($channel->items[0]->snippet->thumbnails->default->url));
        // Скачать обложку канала
        if(isset($channel->items[0]->brandingSettings->image->bannerExternalUrl))
            file_put_contents($this->folder.'/youtube_cover.jpg', file_get_contents($channel->items[0]->brandingSettings->image->bannerExternalUrl));
    }

    /**
     * Обработчик твича
     */
    private function twitch() {
        preg_match('/https:\/\/www.twitch.tv\/(.*)/', $this->url, $this->name);
        $this->name = $this->name[1];
        $ch = curl_init('https://api.twitch.tv/helix/users?login='.$this->name);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer '.TWITCH_TOKEN,
            'Client-Id: '.getenv('TWITCH_CLIENT_ID')
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $json = curl_exec($ch);
        curl_close($ch);
        $response = json_decode($json);
        if(isset($response->data[0])) {
            $user = $response->data[0];
            if(isset($user->email)) unset($user->email);
            $this->description = $user->description;
            $json = json_encode($user, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_NUMERIC_CHECK);
            file_put_contents($this->folder.'/twitch.json', $json);
            // Скачать обложку и аватарку
            if(isset($user->profile_image_url) && !empty($user->profile_image_url)) {
                file_put_contents($this->folder.'/twitch_icon.jpg', file_get_contents($user->profile_image_url));
            }
            if(isset($user->offline_image_url) && !empty($user->offline_image_url)) {
                file_put_contents($this->folder.'/twitch_cover.jpg', file_get_contents($user->offline_image_url));
            }
        }
    }

    /**
     * Обработчик VK
     */
    private function vk() {
        global $vk;
        $group_id = '';
        preg_match('/https:\/\/vk.com\/(.*)/', $this->url, $group_id);
        $group_id = $group_id[1];
        try {
            $group = $vk->groups()->getById(VK_ACCESS_TOKEN, [
                'group_id' => $group_id,
                'fields' => 'cover,description'
            ])[0];
            file_put_contents($this->folder.'/vk.json', json_encode($group, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_NUMERIC_CHECK));
            // Именование
            $this->name = $group['name'];
            $this->description = $group['description'];
            // Скачать аватар группы
            if(isset($group['photo_200']))
                file_put_contents($this->folder.'/vk_icon.jpg', file_get_contents($group['photo_200']));
            elseif(isset($group['photo_100']))
                file_put_contents($this->folder.'/vk_icon.jpg', file_get_contents($group['photo_100']));
            elseif(isset($group['photo_50']))
                file_put_contents($this->folder.'/vk_icon.jpg', file_get_contents($group['photo_50']));
            // Скачать обложку группы
            if(isset($group['cover']) && isset($group['cover']['images'])) {
                $idx = count($group['cover']['images'])-1;
                file_put_contents($this->folder.'/vk_cover.jpg', file_get_contents($group['cover']['images'][$idx]['url']));
            }
        }
        catch (Exception $e) {
            echo ($e->getMessage()), PHP_EOL;
            sleep(1);
        }
    }
}

// Получение данных из Google Spreadsheets
// name, name_variant, youtube, twitch, vk_group
$GSDATA = json_decode(file_get_contents(GS_URL));

$vtuberCollection = [];

// Прогон с обработкой
foreach ($GSDATA as $value) {
    // Шапка
    $vtuber = new stdClass();
    $vtuber->id = md5($value[0]);
    $vtuber->name = $value[0];
    $vtuber->name_variant = $value[1];
    echo 'Обрабатывается ', $vtuber->name, PHP_EOL;
    // Определить рабочую папку
    $vtuber_folder = VTUBERS_DIR.'/'.$vtuber->id;
    // Создать папку
    if(!is_dir($vtuber_folder)) mkdir($vtuber_folder);
    // Получить сведения с площадок
    if(trim($value[2]) != '') $vtuber->youtube = new ChannelInfo($vtuber->id, $value[2], 'youtube');
    if(trim($value[3]) != '') $vtuber->twitch = new ChannelInfo($vtuber->id, $value[3], 'twitch');
    if(trim($value[4]) != '') $vtuber->vk = new ChannelInfo($vtuber->id, $value[4], 'vk');
    // Если никаких социалок нет, то до свидания
    if (
        !isset($vtuber->youtube) && !isset($vtuber->twitch) && !isset($vtuber->vk)
    ) continue;
    // Выбор главной картинки
    if (isset($vtuber->youtube->icon)) copy("$vtuber_folder/youtube_icon.jpg", "$vtuber_folder/main_icon.jpg");
    elseif (isset($vtuber->twitch->icon)) copy("$vtuber_folder/twitch_icon.jpg", "$vtuber_folder/main_icon.jpg");
    elseif (isset($vtuber->vk->icon)) copy("$vtuber_folder/vk_icon.jpg", "$vtuber_folder/main_icon.jpg");
    // Выбор главной обложки
    if (isset($vtuber->youtube->cover)) copy("$vtuber_folder/youtube_cover.jpg", "$vtuber_folder/main_cover.jpg");
    elseif (isset($vtuber->twitch->cover)) copy("$vtuber_folder/twitch_cover.jpg", "$vtuber_folder/main_cover.jpg");
    elseif (isset($vtuber->vk->cover)) copy("$vtuber_folder/vk_cover.jpg", "$vtuber_folder/main_cover.jpg");
    // Собрать в коллекцию
    $vtuberCollection[] = $vtuber;
}

// Сбросить данные
echo 'Сохранение данных...', PHP_EOL;
file_put_contents(VTUBERS_DIR.'/index.json', json_encode($vtuberCollection, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_NUMERIC_CHECK));

// Омагад да это же база данных в оперативной памяти мухахаха
/*$db = new PDO('sqlite::memory:');
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
*/

