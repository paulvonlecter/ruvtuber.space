// Настройки лицевой части
let settings = {
    vtuberIndex: '/upload/vtubers/index.json',
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
    });
    // Отрисовать каталог
    settings.vtubers.forEach(element => {
        // Отрисовать карточки
        //$('<div>')
        //    .addClass('col-sm-6 col-md-4 col-lg-3 col-xl-2 card')
        //    .append('<div class="">')

    });
});

