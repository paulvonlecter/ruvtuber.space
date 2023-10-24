/*
 * В разметку страницы добавить: <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3" id="vtuber-catalogue"></div>
 * P.S. Кринж запостил, но выбросить жалко.
 */
// Настройки лицевой части
let settings = {
    vtuberIndex: 'upload/vtubers/index.json',
    vtubers: []
};
// При загрузке документа
$(document).ready(function (e) {
    // Получить список втуберов
    fetch(settings.vtuberIndex)
    .then(response => response.json())
    .then(vtubers => {
        settings.vtubers = vtubers;
        console.log(settings.vtubers);
    })
    .then(() => {
        // Отрисовать каталог
        settings.vtubers.forEach(element => {
            console.log(element);
            let $buttons = $('<div class="d-inline-block">');
            if (element.vk)
                $('<a class="btn btn-social vk">')
                    .attr('href', element.vk.url)
                    .attr('title', element.vk.name)
                    .html('<i class="fa fa-vk"></i>')
                    .appendTo($buttons);
            if (element.twitch)
                $('<a class="btn btn-social twitch">')
                    .attr('href', element.twitch.url)
                    .attr('title', element.twitch.name)
                    .html('<i class="fa fa-twitch"></i>')
                    .appendTo($buttons);
            if (element.youtube)
                $('<a class="btn btn-social youtube">')
                    .attr('href', element.youtube.url)
                    .attr('title', element.youtube.name)
                    .html('<i class="fa fa-youtube"></i>')
                    .appendTo($buttons);
            let buttonsHtml = $buttons.html();
            // Отрисовать карточки
            let cardHtml = 
            `<div class="col">
                <div class="card mb-3">
                    <div class="row g-0">
                        <div class="col-md-4 size-hack rounded shadow" style="background-image:url(upload/vtubers/${element.id}/main_icon.jpg);background-position:center;background-size:cover;">
                            <img src="upload/vtubers/${element.id}/main_icon.jpg" class="sr-only" alt="">
                        </div>
                        <div class="col-md-8">
                            <div class="card-body">
                                <h5 class="card-title">${element.name}</h5>
                                <p class="card-text" id="${element.id}-desc"></p>
                                ${buttonsHtml}
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;
            $(cardHtml).appendTo('#vtuber-catalogue');
        });
    });
});