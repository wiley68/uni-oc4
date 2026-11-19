# UNI-OC4-ADS-001 — отчет за реализацията

Release контекст (UNI-OC4-ADS-003): собственикът прие OC4-01–OC4-08 с **8/8 PASS**; UNI-OC4-ADS-002 завърши функционалния/security одит. Промяната влиза във **v2.0.4**. Отчетът по-долу запазва историческото състояние на реализацията при версия 2.0.3 и не описва финалното release Git състояние. Актуалните release проверки са в [RELEASE-NOTES-v2.0.4.md](RELEASE-NOTES-v2.0.4.md).

## A. Хранилище и начално състояние

- Път: `/var/www/open40.avalonbg.com/extension/mt_uni_credit`.
- Origin: `git@github.com:wiley68/uni-oc4.git`.
- Клон: `main`, синхронизиран с `origin/main` според локалните Git данни; не е изпълняван fetch.
- HEAD: `faf1c83cd31810abd08e95c53e3a266be6b96796`.
- Начален `git status --untracked-files=all`: чист, без непроследени файлове.
- Прочетени са предоставените глобални AGENTS.md инструкции и `ponytail`; няма AGENTS.md по приложимата родителска верига или в хранилището.

## B. Версия и връзка с инсталацията

Версията остава **2.0.3**, потвърдена в `install.json` и `ModuleConstants::VERSION`. Реалният OpenCart е **4.1.0.3** (`index.php`); CLI проверките използват PHP **8.2.35** и **8.4.1**.

`DIR_EXTENSION` сочи към `/var/www/open40.avalonbg.com/extension/`. Startup extension loader-ите регистрират PHP namespace-ите и Twig пътищата директно към това хранилище. Не е нужно копиране на променените файлове.

`extension/ocmod/` съдържа само `index.html`; няма генерирани заместители на засегнатите файлове. Twig е с `auto_reload=true` и кеш в `/var/www/storage/cache/template/`. Няма променени event дефиниции или installer metadata.

Опитът за read-only проверка на installer/module/event записите не успя: SQL връзката е недостъпна (`mysqli` код 2002). Реалното им текущо състояние не е потвърдено. OpenCart не е стартиран през HTTP или application bootstrap.

## C. Премахнат локален ключ и предишни зависимости

Ключ: `module_mt_uni_credit_advertising_enabled`.

Преди промяната:

1. `ModuleLocalSettings` определяше ключа и default `0`.
2. Admin моделът го включваше в `getDefaultSettings()`; инсталирането инициализираше тези defaults само при липса на настройки.
3. Admin `index()` зареждаше стойността от config и текстовете за опцията. Twig съдържаше checkbox и hidden input; BG/EN езиковите файлове съдържаха label/help.
4. Admin `save()` използваше defaults като allowlist и нормализираше стойността чрез `normalizeFlag()`.
5. Двата homepage event контролера четяха ключа; resolver-ът го включваше в request cache ключа и в `allowsLocalSettings()`. `allowsAssets()` също го изискваше.

След промяната всички тези зависимости са премахнати. Нови инсталации не създават ключа; подадена стара стойност при Save се игнорира. Съществуващият merge в `saveModuleSettings()` запазва историческите записи без промяна, включително при опит за подмяна чрез POST. Не са добавяни изтривания, миграции или нови настройки. Install/uninstall методите и cleanup остават непроменени.

## D. Съществуващ CP механизъм

Двата рекламни обработчика използват общ `HomepageAdvertisingContextResolver`. Той запазва homepage проверката, module status и непразния store-scoped UNICID, след което извиква `ShopConfigurationService::getForPresentation()`.

`HomepageAdvertisingPresenter` прилага `HomepageAdvertisingGate::allowsShop()`: нужни са активни `uni_status` и `uni_container_status`. Ако няма разрешен контекст, не се добавят нито HTML, нито рекламните CSS/JS ресурси. CP предоставя и `uni_picturem`, `uni_backurl`, `uni_container_txt1`, `uni_container_txt2`; обработката им е непроменена.

Съществуващата кеш политика е запазена:

- Валиден кеш: TTL **86400 секунди**; няма CP заявка при свеж snapshot.
- Липсващ/изтекъл кеш: съществуващото обновяване чрез CP `GET /shop`, със съществуващата валидация и заключване.
- Presentation fallback: последен валиден snapshot до **21600 секунди след изтичането**, само при допустима временна грешка или конкуриращо обновяване. Флаговете в използвания snapshot продължават да определят видимостта.
- Невалидна/неизползваема конфигурация и недопустими грешки: рекламата остава скрита; съществуващата обработка на постоянни auth/config грешки е непроменена.
- CP push: `api/shop_cache` проверява подписа/обхвата чрез съществуващия inbound механизъм, санитизира и валидира snapshot-а и го записва чрез `SmartUcfCredentialPersistence`.
- Admin refresh: `refreshBankData()` използва съществуващото `refreshRemote()`.

Промените от CP се виждат според тази кеш политика и следващата заявка; няма добавен механизъм за незабавно заобикаляне на кеша.

## E. Пълен списък на промените

| Файл | Промяна |
| --- | --- |
| `admin/controller/module/mt_uni_credit.php` | Премахнати label/help данни за опцията. |
| `admin/model/module/mt_uni_credit.php` | Премахнат retired ключът от defaults и Save allowlist. |
| `admin/view/template/module/mt_uni_credit.twig` | Премахнат единствено редът с рекламния превключвател. |
| `admin/language/bg-bg/module/mt_uni_credit.php` | Премахнати двата остарели текста. |
| `admin/language/en-gb/module/mt_uni_credit.php` | Премахнати двата остарели текста. |
| `catalog/controller/event/mt_uni_credit_home_controller.php` | Assets вече не зависят от локалния ключ. |
| `catalog/controller/event/mt_uni_credit_home_view.php` | HTML вече не зависи от локалния ключ. |
| `system/library/module_local_settings.php` | Премахнати константите за retired настройката. |
| `system/library/homepage_advertising_gate.php` | Премахнат локалният рекламен аргумент/условие. |
| `system/library/homepage_advertising_context_resolver.php` | Премахнат аргументът и зависимостта в request cache ключа. |
| `tests/Phase11CHomepageAdvertisingTest.php` | Обновени извикванията и eligibility проверките. |
| `tests/Phase7AdminFinalizationTest.php` | Проверява липсата на рекламна опция и наличието на останалите. |
| `tests/CentralizedAdvertisingControlTest.php` | Нови функционални регресии със синтетични данни. |
| `docs/PHASE11C.md` | Обновено рекламното поведение и описанието на реалната кеш политика. |
| `docs/UNI-OC4-ADS-001.md` | Нов отчет. |

## F. Поведение преди/след

При изпълнени останали условия; CP стойността е тази в допустимия presentation snapshot:

| CP реклама | Стар локален ключ | Преди | След |
| --- | --- | --- | --- |
| ON | 0 | Скрита | Може да се показва |
| ON | 1 | Може да се показва | Може да се показва |
| ON | Липсва | Скрита | Може да се показва |
| OFF | 0 | Скрита | Скрита |
| OFF | 1 | Скрита | Скрита |
| OFF | Липсва | Скрита | Скрита |

Извън началната страница, при изключен модул, липсващ UNICID или неактивен CP shop рекламата и ресурсите остават скрити.

## G. Изпълнени проверки и ограничения

Използван е PHPUnit **11.5.56** от съществуващия Composer lock. Test tooling е инсталиран от локалния Composer cache в временна директория под `/tmp/uni-oc4-ads001-*`, без мрежа, plugins/scripts или промени по зависимостите на хранилището. Временният bootstrap запазва autoload правилата от `tests/bootstrap.php`, като насочва към това tooling. Използвани са `--no-configuration`, `--do-not-cache-result`, `MT_UNI_CREDIT_INTEGRATION=0` и само изрично избрани безопасни тестове; проектният `phpunit.xml` включва DB integration по подразбиране и не е използван.

- **41 теста / 1703 assertions: PASS** под PHP **8.4.1** и отделно под **8.2.35**.
- Включени suites: `CentralizedAdvertisingControlTest`, `Phase11CHomepageAdvertisingTest`, `Phase7AdminFinalizationTest`, `Phase1InstallContractTest`, `Phase1CompatibilityTest`, `ExtensionMetadataTest`, `ModuleVersionFreezeTest`, `Phase4AdminPresenterTest`.
- Допълнително **2 теста / 29 assertions: PASS** от `Phase4AdminBankActionsTest`: само `testAdminTwigExposesSaveRefreshAndJournalWithoutTechnicalAuthButtons` и `testControllerUsesPostRedirectRefreshBankDataRoute` под PHP 8.4.
- Новата функционална матрица изпълнява реалните homepage callbacks в **144 комбинации**, с fake DB/config/document/view loader: CP ON/OFF × стар ключ 0/1/липсва × шест route контекста × четири eligibility състояния.
- Реален admin Save срещу in-memory settings проверява игнориран POST за retired ключа, запазени исторически 0/1/липсва и непроменено записване на останалите настройки.
- Реалните resolver/service/cache компоненти с fake transport и fake SQL boundary проверяват свеж кеш без заявка, refresh към CP OFF, TTL, push ON/OFF, отказ на невалиден push, manual refresh, последен валиден ON/OFF snapshot, твърде стар/липсващ кеш и невалиден отговор.
- Съществуващите тестове покриват event registration, multistore, URL/text обработка, mobile/desktop данни, asset versioning и frontend договорите.
- PHP syntax: **12 променени PHP файла — PASS** с PHP 8.2 и 8.4.
- `node --check catalog/view/javascript/mt_uni_credit_homepage_advertising.js`: **PASS**.
- Twig syntax за admin формата и рекламния template, с изключен Twig cache: **PASS**.
- `git diff --check`: **PASS**.

Не са изпълнявани целият suite, DB integration, packaging тестове, install/uninstall hooks или реални CP/bank заявки. Не са променяни реални SQL данни. Push/manual refresh са проверени през общите service/persistence компоненти, без HTTP към магазина. View loader-ът във функционалната матрица е fake; браузърното JS/CSS поведение и реалният Twig изход остават за приемане от собственика.

## H. Запазен обхват

Непроменени са финансовите формули, product/cart/checkout financing, поръчки и статуси, банкови комуникации и email протоколи, CP API договори/крайни точки/payload-и, auth/credentials и BORICA. Рекламният frontend Twig/HTML, JavaScript, CSS, изображения и връзки са непроменени. Няма нови зависимости или миграции.

## I. Активиране и браузърно приемане

Няма необходимост от копиране, reinstall или OCMOD refresh по установената файлова структура. Event дефинициите са непроменени. Twig има auto-reload; не е извършвано изчистване на кеш. Ако уеб процесът продължава да сервира стар PHP/Twig код, собственикът да координира подходящото обновяване според реалните OPcache/cache настройки. Web OPcache и SQL event/installer състоянието не са потвърдени.

Предложени ръчни проверки от собственика:

1. Admin: опцията за реклама липсва; останалите полета са налични. При отделно одобрено Save историческият ключ се запазва, а останалите настройки работят.
2. CP ON и валиден shop snapshot: рекламата се показва на началната страница независимо от историческия ключ, включително при текуща стара стойност 0. Не е нужно изкуствено редактиране на SQL настройките.
3. Desktop: отваряне/затваряне на панела, Escape и връщане на фокуса; mobile: съществуващата външна връзка. Текстове, изображения и CTA са запазени.
4. CP OFF след координиран push/refresh: няма рекламна маркировка и рекламни CSS/JS заявки. Нов CP ON snapshot възстановява рекламата при валидни условия.
5. Product, category, cart и checkout: няма homepage реклама. Изключен модул, липсващ UNICID или CP `uni_status=0` продължават да я скриват.

Не са изчиствани кешове/modifications, рестартирани услуги или променяни права/собственост. Не са изпращани банкови заявки, имейли или кредитни заявления.

## J. Финално Git състояние

Клонът и HEAD са непроменени. Има **13 modified** проследени файла и **2 untracked** нови файла — точно инвентарът в E. Всички промени са локални и uncommitted. Няма version bump, commit, push, tag, installer ZIP, release или deployment.

Реализацията и автоматизираната проверка са завършени; очаква се приемане от собственика.
