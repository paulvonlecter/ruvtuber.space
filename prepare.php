<?php
/**
 * Сборщик открытых данных для главной страницы RUVTuber Space
 */

// Установка временной зоны
date_default_timezone_set('Europe/Moscow');

// Определить константы
define('VK_CLIENT_ID', getenv('VK_CLIENT_ID'));
if(getenv('VK_TOKEN')) {
    define('VK_ACCESS_TOKEN', getenv('VK_TOKEN'));
} else {
    define('VK_ACCESS_TOKEN', getenv('VK_SERVICE_TOKEN'));
}

// Проверка на целостность окружения
if(!VK_ACCESS_TOKEN && !VK_CLIENT_ID) {
    echo 'Некорректно настроено окружение', PHP_EOL;
    exit(1);
}

// Проверить на наличие композера
if(!file_exists(__DIR__.'/vendor/autoload.php')) {
    echo 'Composer не установлен', PHP_EOL;
    exit(1);
}

// Перечень страниц ВК
define('VK_VTUBERS_FILE', __DIR__.'/_json/vk.txt');
// Папка с данными виртуальных ютуберов
define('VK_VTUBERS_DIR', __DIR__.'/_json/vtubers');
// Папка с новостями
define('NEWS_DIR', __DIR__.'/_json/news');
// Папка с постами
define('POSTS_DIR', __DIR__.'/_posts');

// Подготовка папки с новостями
if(!is_dir(NEWS_DIR)) {
    echo 'Возможно первый запуск, создание папки с новостями', PHP_EOL;
    mkdir(NEWS_DIR, 0777, TRUE);
}
// Подготовка папки с новостями
if(!is_dir(POSTS_DIR)) {
    echo 'Возможно первый запуск, создание папки с постами', PHP_EOL;
    mkdir(POSTS_DIR, 0777, TRUE);
}

// Включение бибилотек
require_once(__DIR__.'/vendor/autoload.php');
// Подключение клиента
$vk = new VK\Client\VKApiClient();

/**
 * 1. Получение сведений о группах
 */

// Получить перечень объектов-групп
$vtuber_group_ids = array();
preg_match_all('/https:\/\/vk.com\/(.*)/', file_get_contents(VK_VTUBERS_FILE), $vtuber_group_ids);
$vtuber_group_ids = $vtuber_group_ids[1];
sort($vtuber_group_ids);
$vtuber_group_ids = array_unique($vtuber_group_ids);
echo '1. Получение сведений об объектах', PHP_EOL;
foreach ($vtuber_group_ids as $group_id) {
    // Экономия времени
    if (empty((string)$group_id)) continue;
    $group_filename = VK_VTUBERS_DIR.'/'.$group_id.'.json';
    if (is_file($group_filename)) continue;
    // Буэээ
    echo 'Получение данных для паблика ', $group_id, PHP_EOL;
    try {
        $group = @$vk->groups()->getById(VK_ACCESS_TOKEN, [
            'group_id' => $group_id,
        ])[0];
    }
    catch (Exception $e) {
        echo ($e->getMessage()), PHP_EOL;
        sleep(1);
        continue;
    }
    $group_json = json_encode($group, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_NUMERIC_CHECK);
    echo 'Данные паблика ', $group['name'], ' получены. Сохранение в файл ', $group_id, '.json', PHP_EOL;
    file_put_contents($group_filename, $group_json);
    // Очистка памяти
    unset($group, $group_filename, $group_json);
    // Задержка, потому что ВК рейт-лимит.
    sleep(1);
}
unset($vtuber_group_ids);
echo 'Этап завершён', PHP_EOL;
/**
 * 2. Систематизация
 */

echo '2. Систематизация объектов', PHP_EOL;
// Омагад да это же база данных в оперативной памяти мухахаха
$db = new PDO('sqlite::memory:');
$db->exec("CREATE TABLE 'groups'
    (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        screen_name TEXT NOT NULL,
        photo_50 TEXT NOT NULL
    )
");

// Получить сведения о втуберских группах
$vtuber_group_files = glob(VK_VTUBERS_DIR.'/*.json');
natsort($vtuber_group_files);
// Перебрать список вчубесов
foreach ($vtuber_group_files as $vtuber_file) {
    $vtuber = json_decode(file_get_contents($vtuber_file));
    if((bool)$vtuber->is_closed) continue;
    echo 'Рассматривается виртуальный ютубер ', "[{$vtuber->id}] ", $vtuber->name, PHP_EOL;
    $sth = $db->prepare("INSERT OR IGNORE INTO 'groups' (id, name, screen_name, photo_50) VALUES (?, ?, ?, ?)");
    $sth->execute(array($vtuber->id, $vtuber->name, $vtuber->screen_name, $vtuber->photo_50));
}
unset($vtuber_group_files);
echo 'Добавление в базу данных завершено.', PHP_EOL;

/**
 * 3. Получение новостей
 */

echo '3. Получение новостей', PHP_EOL;
// Дополнить схему
$db->exec("CREATE TABLE 'wall'
    (
        'id' INTEGER PRIMARY KEY,
        'owner_id' INTEGER NOT NULL,
        'author_id' INTEGER,
        'date' INTEGER NOT NULL,
        'text' TEXT NOT NULL,
        'attachments' TEXT
    )
");
// Получить список втуберов
$vtubers = $db->query('SELECT * FROM groups ORDER BY screen_name');
// Перебрать новости каждого
foreach ($vtubers as $vtuber) {
    echo "Рассматривается [{$vtuber['screen_name']}] {$vtuber['name']}", PHP_EOL;
    if(glob(NEWS_DIR.'/wall-'.$vtuber['id'].'_*.json')) continue;
    // Получить новости со стены
    try {
        $news = $vk->wall()->get(VK_ACCESS_TOKEN, [
            'owner_id' => ('-'.$vtuber['id']),
            'count' => 5,
            'extended' => 1,
        ]);
    } catch (Exception $e) {
        echo $e->getMessage();
        continue;
    }
    // Приготовить коллектор данных
    $data = array();
    // Обработать выхлоп
    foreach($news['items'] as $item) {
        // Экономия времени
        if($item['post_type'] != 'post') continue;
        // Сформировать имя файла
        $filename = NEWS_DIR."/wall{$item['owner_id']}_{$item['id']}.json";
        // Перейти к следующему, если файл существует
        if(file_exists($filename)) continue;
        // Получить JSON-представление
        $contents = json_encode($item, JSON_NUMERIC_CHECK | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        // Записать в файл
        file_put_contents($filename, $contents);
        echo 'Запись ', $item['id'], ' обработана и записана в файл wall', $item['owner_id'], '_', $item['id'], '.json', PHP_EOL;
    }
    // Задержка, потому что ВК рейт-лимит.
    sleep(1);
}
unset($vtubers);

/**
 * 3.5 Перегонка в базу
 */

// Получить список постов
$post_files = glob(NEWS_DIR.'/*.json');
foreach ($post_files as $post_file) {
    $post_file_str = file_get_contents($post_file);
    $item = json_decode($post_file_str, null);
    // Записать в базу
    $sth = $db->prepare("INSERT OR IGNORE INTO 'wall' ('id', 'owner_id', 'author_id', 'date', 'text', 'attachments') VALUES (?, ?, ?, ?, ?, ?)");
    $sth->execute(
        array(
            $item->id,
            $item->owner_id,
            abs($item->owner_id),
            $item->date,
            $item->text,
            json_encode($item->attachments, JSON_NUMERIC_CHECK | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE),
        )
    );
}

/**
 * 4. Публикация новостей
 */
echo '4. Публикация новостей', PHP_EOL;
// Получение списка новостей
$news = $db->query("SELECT wall.*, groups.name AS author, groups.screen_name AS screen_name
    FROM wall
    LEFT JOIN groups ON wall.author_id = groups.id
    GROUP BY owner_id ORDER BY date DESC");
foreach ($news as $post) {
    $body = $post['text'];
    $post_date = date('Y-m-d', $post['date']);
    $file_name = $post_date.'-wall'.$post['owner_id'].'_'.$post['id'].'.md';
    $file_category = (isset($post['screen_name'])?(string)$post['screen_name']:'club'.$post['author_id']);
    $file_author = preg_replace('/[\x00-\x1F\x7F]/u', '', $post['author']);
    $file_str = <<<MARKDOWN
---
layout: post
category: {$file_category}
author: "{$file_author} "
---

{$body}
MARKDOWN;
    file_put_contents(POSTS_DIR.'/'.$file_name, $file_str);
}