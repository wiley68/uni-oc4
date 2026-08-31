<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\HomepageAdvertisingContextResolver;
use Opencart\System\Library\Extension\MtUniCredit\HomepageAdvertisingGate;
use Opencart\System\Library\Extension\MtUniCredit\HomepageAdvertisingPresenter;
use Opencart\System\Library\Extension\MtUniCredit\ModuleAssetVersion;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings;
use Opencart\System\Library\Extension\MtUniCredit\ModuleSettingCipher;
use Opencart\System\Library\Extension\MtUniCredit\InMemoryModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationService;
use Opencart\System\Library\Extension\MtUniCredit\StorefrontRouteResolver;
use PHPUnit\Framework\TestCase;

final class Phase11CHomepageAdvertisingTest extends TestCase
{
    private const LOGO = 'extension/mt_uni_credit/catalog/view/image/product/uni_logo.svg';

    protected function setUp(): void
    {
        HomepageAdvertisingContextResolver::resetRequestCache();
    }

    /** @return array<string, mixed> */
    private function validShop(): array
    {
        return [
            'uni_status'            => 1,
            'uni_container_status'  => 1,
            'uni_backurl'           => 'https://cdn.example.test/info',
            'uni_picturem'          => 'https://r2.example.test/mobile.jpg',
            'uni_container_txt1'    => ' <b>Заглавие</b> ',
            'uni_container_txt2'    => 'Текст от КП',
        ];
    }

    public function testGateVisibilityMatrix(): void
    {
        $gate = new HomepageAdvertisingGate();

        self::assertTrue($gate->allowsPage('common/home'));
        self::assertTrue($gate->allowsPage(''));
        self::assertFalse($gate->allowsPage('product/product'));
        self::assertFalse($gate->allowsPage('checkout/cart'));
        self::assertFalse($gate->allowsPage('checkout/checkout'));
        self::assertFalse($gate->allowsPage('product/category'));

        self::assertTrue($gate->allowsLocalSettings(true, true, true, 'SHOP-1'));
        self::assertFalse($gate->allowsLocalSettings(false, true, true, 'SHOP-1'));
        self::assertFalse($gate->allowsLocalSettings(true, false, true, 'SHOP-1'));
        self::assertFalse($gate->allowsLocalSettings(true, true, false, 'SHOP-1'));
        self::assertFalse($gate->allowsLocalSettings(true, true, true, ''));

        self::assertTrue($gate->allowsAssets('common/home', true, true, true, 'SHOP-1'));
        self::assertFalse($gate->allowsAssets('product/product', true, true, true, 'SHOP-1'));
        self::assertFalse($gate->allowsAssets('common/home', true, true, false, 'SHOP-1'));

        self::assertTrue($gate->allowsShop(['uni_status' => 1, 'uni_container_status' => 1]));
        self::assertTrue($gate->allowsShop(['uni_status' => 'Yes', 'uni_container_status' => '1']));
        self::assertFalse($gate->allowsShop(['uni_status' => 0, 'uni_container_status' => 1]));
        self::assertFalse($gate->allowsShop(['uni_status' => 1, 'uni_container_status' => 0]));
        self::assertFalse($gate->allowsShop([]));
    }

    public function testPresenterNormalizesUrlsTextAndMobileDesktopImages(): void
    {
        $presenter = new HomepageAdvertisingPresenter(new HomepageAdvertisingGate());
        $shop = $this->validShop();

        $desktop = $presenter->present($shop, false, self::LOGO);
        self::assertIsArray($desktop);
        self::assertFalse($desktop['is_mobile']);
        self::assertSame(self::LOGO, $desktop['float_image_url']);
        self::assertSame('https://r2.example.test/mobile.jpg', $desktop['picture_url']);
        self::assertSame('https://cdn.example.test/info', $desktop['backurl']);
        self::assertSame('Заглавие', $desktop['txt1']);
        self::assertSame('Текст от КП', $desktop['txt2']);

        $mobile = $presenter->present($shop, true, self::LOGO);
        self::assertTrue($mobile['is_mobile']);
        self::assertSame('https://r2.example.test/mobile.jpg', $mobile['float_image_url']);

        $unsafe = $presenter->present(
            [
                'uni_status'           => 1,
                'uni_container_status' => 1,
                'uni_backurl'          => 'javascript:alert(1)',
                'uni_picturem'         => '/relative.jpg',
            ],
            true,
            self::LOGO
        );
        self::assertSame('', $unsafe['backurl']);
        self::assertSame('', $unsafe['picture_url']);
        self::assertSame(self::LOGO, $unsafe['float_image_url']);

        self::assertNull($presenter->present(['uni_status' => 0, 'uni_container_status' => 1], false, self::LOGO));
    }

    public function testLocalAdvertisingSettingKeyIsFrozen(): void
    {
        self::assertSame('module_mt_uni_credit_advertising_enabled', ModuleLocalSettings::ADVERTISING_ENABLED);
    }

    public function testEventRegistryRegistersHomepageAdvertisingHooks(): void
    {
        $triggers = array_column(EventRegistry::definitions(), 'trigger');
        self::assertContains('catalog/controller/common/home/before', $triggers);
        self::assertContains('catalog/view/common/footer/after', $triggers);
        self::assertContains('module_mt_uni_credit_before_home_controller', EventRegistry::eventCodes());
        self::assertContains('module_mt_uni_credit_after_home_footer', EventRegistry::eventCodes());
    }

    public function testHomeControllerUsesCacheOnlyShopConfiguration(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_home_controller.php');
        $resolver = (string) file_get_contents(dirname(__DIR__) . '/system/library/homepage_advertising_context_resolver.php');
        self::assertStringContainsString('HomepageAdvertisingContextResolver', $controller);
        self::assertStringContainsString('getCachedOnly()', $resolver);
        self::assertStringNotContainsString('->get(true)', $controller);
        self::assertStringNotContainsString('->refresh(', $controller);
        self::assertStringContainsString('ModuleAssetVersion::href', $controller);
        self::assertStringContainsString('mt_uni_credit_homepage_advertising.css', $controller);
        self::assertStringContainsString('mt_uni_credit_homepage_advertising.js', $controller);
    }

    public function testHomeViewInjectsOnlyOnHomepageFooter(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_home_view.php');
        self::assertStringContainsString("if (\$route !== 'common/footer')", $source);
        self::assertStringContainsString('StorefrontRouteResolver::isHomepageRoute', $source);
        self::assertStringContainsString('mt_uni_credit_homepage_advertising', $source);
    }

    public function testTwigJsCssContractMatchesPs9ParityAndAvoidsFinancingModalCollision(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_homepage_advertising.twig');
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_homepage_advertising.js');
        $css = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_homepage_advertising.css');

        self::assertStringContainsString('id="mt-uni-credit-advertising-root"', $twig);
        self::assertStringContainsString('data-mt-uni-credit-advertising', $twig);
        self::assertStringContainsString('id="mt-uni-credit-advertising-panel"', $twig);
        self::assertStringContainsString('role="dialog"', $twig);
        self::assertStringContainsString('aria-modal="true"', $twig);
        self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $twig);
        $lang = (string) file_get_contents(dirname(__DIR__) . '/catalog/language/bg-bg/module/mt_uni_credit_home.php');
        self::assertStringContainsString('text_panel_cta', $twig);
        self::assertStringContainsString('ИНФОРМАЦИЯ ЗА ОНЛАЙН ПАЗАРУВАНЕ НА КРЕДИТ', $lang);
        self::assertStringNotContainsString('onclick=', $twig);
        self::assertStringNotContainsString('mt-uni-credit-product-modal', $twig);
        self::assertStringNotContainsString('mt-uni-credit-cart-modal', $twig);

        self::assertStringContainsString('PANEL_ID = "mt-uni-credit-advertising-panel"', $js);
        self::assertStringContainsString('data-mt-uni-credit-advertising-toggle', $js);
        self::assertStringContainsString('data-mt-uni-credit-advertising-open', $js);
        self::assertStringContainsString('window.open', $js);
        self::assertStringContainsString('noopener', $js);
        self::assertStringContainsString('event.key === "Escape"', $js);
        self::assertStringNotContainsString('mt-uni-credit-product-modal', $js);
        self::assertStringNotContainsString('#modal', $js);

        self::assertStringContainsString('position: fixed', $css);
        self::assertStringContainsString('width: 120px', $css);
        self::assertStringContainsString('height: 60px', $css);
        self::assertStringContainsString('left: 0', $css);
        self::assertStringContainsString('width: 410px', $css);
        self::assertStringContainsString('height: 260px', $css);
        self::assertStringContainsString('#d20210', $css);
        self::assertStringContainsString('"Roboto Condensed"', $css);
        self::assertStringNotContainsString('fonts.googleapis.com', $css);
    }

    public function testAdvertisingAssetsUseFilemtimeCacheBusting(): void
    {
        $cssVer = ModuleAssetVersion::forRelativePath('catalog/view/stylesheet/mt_uni_credit_homepage_advertising.css');
        $jsVer = ModuleAssetVersion::forRelativePath('catalog/view/javascript/mt_uni_credit_homepage_advertising.js');
        self::assertNotSame(ModuleConstants::VERSION, $cssVer);
        self::assertNotSame(ModuleConstants::VERSION, $jsVer);
        self::assertStringContainsString('?ver=' . $cssVer, ModuleAssetVersion::href('catalog/view/stylesheet/mt_uni_credit_homepage_advertising.css'));
    }

    public function testResolverUsesSeparateStoreScopes(): void
    {
        $settings0 = new InMemoryModuleSettingStore();
        $settings0->set(0, ModuleCredentialsRepository::UNICID_SETTING, 'STORE-0');
        $settings1 = new InMemoryModuleSettingStore();
        $settings1->set(1, ModuleCredentialsRepository::UNICID_SETTING, 'STORE-1');
        $cipher = new ModuleSettingCipher(str_repeat('a', 32));
        $credentials0 = new ModuleCredentialsRepository($settings0, $cipher);
        $credentials1 = new ModuleCredentialsRepository($settings1, $cipher);

        $shop0 = $this->createMock(ShopConfigurationService::class);
        $shop0->method('getCachedOnly')->willReturn($this->validShop());
        $shop1 = $this->createMock(ShopConfigurationService::class);
        $shop1->method('getCachedOnly')->willReturn(array_merge($this->validShop(), [
            'uni_container_txt1' => 'Store 1 title',
        ]));

        $resolver0 = new HomepageAdvertisingContextResolver(
            new HomepageAdvertisingGate(),
            new HomepageAdvertisingPresenter(new HomepageAdvertisingGate()),
            $shop0,
            $credentials0,
            0
        );
        $resolver1 = new HomepageAdvertisingContextResolver(
            new HomepageAdvertisingGate(),
            new HomepageAdvertisingPresenter(new HomepageAdvertisingGate()),
            $shop1,
            $credentials1,
            1
        );

        $ctx0 = $resolver0->resolve(true, true, true, false, self::LOGO);
        $ctx1 = $resolver1->resolve(true, true, true, false, self::LOGO);
        self::assertSame('Заглавие', $ctx0['txt1'] ?? '');
        self::assertSame('Store 1 title', $ctx1['txt1'] ?? '');
    }

    public function testCacheRefreshReflectsUpdatedShopSnapshot(): void
    {
        $settings = new InMemoryModuleSettingStore();
        $settings->set(0, ModuleCredentialsRepository::UNICID_SETTING, 'SHOP-1');
        $cipher = new ModuleSettingCipher(str_repeat('b', 32));
        $credentials = new ModuleCredentialsRepository($settings, $cipher);

        $shopService = $this->createMock(ShopConfigurationService::class);
        $shopService->method('getCachedOnly')->willReturnOnConsecutiveCalls(
            $this->validShop(),
            array_merge($this->validShop(), ['uni_container_txt1' => 'Updated title'])
        );

        $resolver = new HomepageAdvertisingContextResolver(
            new HomepageAdvertisingGate(),
            new HomepageAdvertisingPresenter(new HomepageAdvertisingGate()),
            $shopService,
            $credentials,
            0
        );

        self::assertSame('Заглавие', $resolver->resolve(true, true, true, false, self::LOGO)['txt1'] ?? '');
        HomepageAdvertisingContextResolver::resetRequestCache();
        self::assertSame('Updated title', $resolver->resolve(true, true, true, false, self::LOGO)['txt1'] ?? '');
    }

    public function testRouteResolverHomepageOnly(): void
    {
        self::assertTrue(StorefrontRouteResolver::isHomepageRoute(''));
        self::assertTrue(StorefrontRouteResolver::isHomepageRoute('common/home'));
        self::assertFalse(StorefrontRouteResolver::isHomepageRoute('product/product'));
        self::assertFalse(StorefrontRouteResolver::isHomepageRoute('checkout/cart'));
    }

    public function testModuleVersionRemainsFrozen(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testDomFixtureOpenCloseEscapeAndFocusRestore(): void
    {
        $fixture = <<<'HTML'
<div id="mt-uni-credit-advertising-root" data-mt-uni-credit-advertising>
  <button type="button" class="mt-uni-credit-advertising__float" data-mt-uni-credit-advertising-toggle aria-controls="mt-uni-credit-advertising-panel" aria-expanded="false">Open</button>
  <div id="mt-uni-credit-advertising-panel" class="mt-uni-credit-advertising__panel" role="dialog" aria-modal="true" hidden>
    <button type="button" data-mt-uni-credit-advertising-close>Close</button>
    <a href="https://cdn.example.test/info" target="_blank" rel="noopener noreferrer">CTA</a>
  </div>
</div>
HTML;

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadHTML($fixture));
        $panel = $dom->getElementById('mt-uni-credit-advertising-panel');
        $toggle = $dom->getElementsByTagName('button')->item(0);
        self::assertNotNull($panel);
        self::assertNotNull($toggle);
        self::assertSame('false', $toggle->getAttribute('aria-expanded'));

        $panel->removeAttribute('hidden');
        $panel->setAttribute('class', 'mt-uni-credit-advertising__panel is-visible');
        $toggle->setAttribute('aria-expanded', 'true');
        self::assertStringContainsString('is-visible', $panel->getAttribute('class'));

        $panel->setAttribute('hidden', 'hidden');
        $panel->setAttribute('class', 'mt-uni-credit-advertising__panel');
        $toggle->setAttribute('aria-expanded', 'false');
        self::assertSame('false', $toggle->getAttribute('aria-expanded'));
    }
}
