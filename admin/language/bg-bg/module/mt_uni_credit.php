<?php

$_['heading_title']           = 'УниКредит покупки на Кредит';
$_['text_extension']          = 'Разширения';
$_['text_home']               = 'Начало';
$_['text_success']            = 'Настройките са запазени успешно.';
$_['text_edit']               = 'Настройки на модула';
$_['text_enabled']            = 'Включен';
$_['text_disabled']           = 'Изключен';
$_['text_yes']                = 'Да';
$_['text_no']                 = 'Не';
$_['text_health']             = 'Диагностика';
$_['text_health_placeholder'] = 'Диагностика на deployment и банкови данни. Токени, секрети и PEM никога не се показват.';
$_['text_version']            = 'Версия';
$_['text_extension_code']     = 'Код на разширението';
$_['text_events_registered']  = 'Регистрирани събития';
$_['text_cp_endpoint']        = 'Банков endpoint';
$_['text_environment_config'] = 'Environment конфигурация';
$_['text_secret_config']      = 'Secret конфигурация';
$_['text_certificate']        = 'Сертификат';
$_['text_private_key']        = 'Частен ключ';
$_['text_certificate_validity'] = 'Валидност на сертификата';
$_['text_certificate_not_after'] = 'Валиден до';
$_['text_certificate_key_match'] = 'Съвпадение сертификат / ключ';
$_['text_deployment_ready']   = 'Deployment готов';
$_['text_bank_actions']       = 'Банкови данни';
$_['text_bank_actions_help']  = 'Запишете UNICID и секрета, след което обновете данните от банката. Автентикацията е автоматична.';
$_['text_cp_auth_state']      = 'Състояние на връзката';
$_['text_cp_token_expires']   = 'Сесия изтича';
$_['text_cp_cache_present']   = 'Кеш с банкови данни';
$_['text_cp_cache_fetched_at'] = 'Последно обновяване';
$_['text_cp_cache_expires_at'] = 'Кешът изтича';
$_['text_cp_cache_fresh']     = 'Кешът е актуален';

$_['text_auth_state_missing_credentials'] = 'Липсват credentials';
$_['text_auth_state_disconnected'] = 'Няма активна сесия';
$_['text_auth_state_authenticated'] = 'Активна сесия';
$_['text_auth_state_expired'] = 'Сесията е изтекла';

$_['entry_status']            = 'Статус';
$_['entry_unicid']            = 'Уникален идентификационен код на магазина Ви';
$_['entry_secret']            = 'Секретен код на магазина Ви';
$_['help_secret']             = 'Секретен код от банката. Никога не се показва след запазване.';
$_['text_secret_configured']  = 'Секретът е конфигуриран';
$_['text_secret_keep_current'] = 'Оставете празно, за да запазите текущия секрет.';
$_['help_journal_unavailable'] = 'Журналът с операции ще е наличен в следваща фаза (диагностика на банкови заявки).';

$_['button_save']             = 'Запиши настройките';
$_['button_back']             = 'Назад';
$_['button_refresh_bank_data'] = 'Обнови данните от банката';
$_['button_download_journal'] = 'Изтегли журнал операции';

$_['text_bank_data_refreshed'] = 'Данните от банката са обновени успешно.';
$_['text_bank_data_refreshed_at'] = 'Време на обновяване: %s.';
$_['text_bank_data_scheme_count'] = 'Схеми в кеша: %d.';

$_['error_bank_unicid_missing'] = 'Липсва UNICID. Запишете настройките и опитайте отново.';
$_['error_bank_secret_missing'] = 'Липсва секретен код на магазина. Запишете настройките и опитайте отново.';
$_['error_bank_secret_unreadable'] = 'Съхраненият секрет не може да бъде прочетен. Въведете го отново и запишете.';
$_['error_bank_authentication_failed'] = 'Удостоверенията към банката бяха отхвърлени.';
$_['error_bank_shop_snapshot_invalid'] = 'Данните от банката са невалидни и не бяха приложени. Предишната конфигурация е запазена.';
$_['error_bank_transient_failure'] = 'Връзката с банката е временно недостъпна. Моля, опитайте отново.';
$_['error_bank_request_failed'] = 'Данните не могат да бъдат обновени. Проверете настройките и опитайте отново.';

$_['error_secret_required']   = 'Секретният код на магазина е задължителен.';
$_['error_unicid_required']   = 'UNICID е задължителен.';

$_['text_health_status_healthy'] = 'OK';
$_['text_health_status_missing'] = 'Липсва';
$_['text_health_status_invalid'] = 'Невалиден';
$_['text_health_status_unreadable'] = 'Нечетим';
$_['text_health_status_expired'] = 'Изтекъл';
$_['text_health_status_not_yet_valid'] = 'Още невалиден';
$_['text_health_status_mismatch'] = 'Несъвпадение';
$_['text_health_status_unknown'] = 'Неизвестно';

$_['error_permission']        = 'Предупреждение: Нямате права да променяте настройките на модула УниКредит!';
