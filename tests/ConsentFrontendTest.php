<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class ConsentFrontendTest extends TestCase
{
    public function testRenderedConsentHtmlRoundTripsThroughDomWithAutoescapeDisabled(): void
    {
        if (!class_exists(\Twig\Environment::class)) {
            $autoload = getenv('MT_UNI_CREDIT_TWIG_AUTOLOAD') ?: '/var/www/storage/vendor/autoload.php';
            if (!is_file($autoload)) self::markTestSkipped('Existing OpenCart Twig runtime unavailable; no dependency is installed.');
            require_once $autoload;
        }
        if (!class_exists(\DOMDocument::class)) self::markTestSkipped('DOM extension unavailable.');
        $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/catalog/view/template'), ['autoescape'=>false, 'cache'=>false]);
        $templates = ['module/mt_uni_credit_product_modal.twig','module/mt_uni_credit_cart_modal.twig','payment/mt_uni_credit.twig'];
        $names = ['Terms "A"','Terms & Conditions','Условия за кредит — България',"O'Reilly & <special> Conditions",'Terms &amp; Conditions','Terms " onmouseover="alert(1)'];
        foreach ($templates as $template) foreach ($names as $name) foreach ([true,false] as $mandatory) {
            $consent = ['id'=>1,'interest_scope'=>'all','name'=>$name,'url'=>'https://example.test/terms?x="A"&y=1','has_checkbox'=>$mandatory];
            $html = $twig->render($template, ['mt_uni_credit'=>['product_id'=>42,'modal'=>['consents'=>[$consent]]], 'consents'=>[$consent], 'mt_uni_credit_bootstrap_json'=>'{}']);
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($dom);
            $rows = $xpath->query('//*[@data-mtuc-consent-row]');
            self::assertCount(1,$rows,$template);
            $row = $rows->item(0);
            // data-* attribute values are the values exposed by DOMStringMap/dataset.
            self::assertSame($name,$row->getAttribute('data-mtuc-consent-name'));
            self::assertSame($consent['url'],$row->getAttribute('data-mtuc-consent-url'));
            self::assertSame('all',$row->getAttribute('data-mtuc-consent-scope'));
            $links = $xpath->query('.//a',$row);
            self::assertCount(1,$links);
            self::assertSame($name,$links->item(0)->textContent);
            self::assertSame($consent['url'],$links->item(0)->getAttribute('href'));
            self::assertSame(0,$xpath->query('.//*[@onmouseover or @onerror or self::special]',$row)->length);
            self::assertSame($mandatory ? 1 : 0,$xpath->query('.//input',$row)->length);
        }
    }

    public function testProductionEventHandlersAndCheckoutMountWithDomFacade(): void
    {
        $binary = trim((string) shell_exec('command -v node'));
        if ($binary === '') self::markTestSkipped('Node is unavailable; deterministic frontend harness requires Node.');
        $lines = []; $status = 0;
        exec(escapeshellarg($binary) . ' ' . escapeshellarg(__DIR__ . '/frontend/consent-lifecycle.cjs') . ' 2>&1', $lines, $status);
        self::assertSame(0, $status, implode("\n", $lines));
        self::assertCount(3, array_filter($lines, static fn(string $line): bool => str_starts_with($line, 'PASS ')));
    }
}
