<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Huncwot\UhoFramework\_uho_social;

class UhoSocialTest extends TestCase
{
    // ==================== Facebook Share Tests ====================

    #[Test]
    public function getFacebookShareReturnsValidUrl(): void
    {
        $url = 'https://example.com/article';
        $result = _uho_social::getFacebookShare($url);

        $this->assertStringStartsWith('https://www.facebook.com/sharer/sharer.php', $result);
        $this->assertStringContainsString('u=', $result);
    }

    #[Test]
    public function getFacebookShareEncodesUrl(): void
    {
        $url = 'https://example.com/article?id=123&name=test';
        $result = _uho_social::getFacebookShare($url);

        $this->assertStringContainsString(urlencode($url), $result);
    }

    #[Test]
    public function getFacebookShareHandlesPolishCharacters(): void
    {
        $url = 'https://example.com/artykuł-o-żółwiach';
        $result = _uho_social::getFacebookShare($url);

        $this->assertStringStartsWith('https://www.facebook.com/sharer/sharer.php', $result);
        // URL should be encoded
        $this->assertStringNotContainsString('żółwiach', $result);
    }

    // ==================== LinkedIn Share Tests ====================

    #[Test]
    public function getLinkedinShareReturnsValidUrl(): void
    {
        $url = 'https://example.com/article';
        $result = _uho_social::getLinkedinShare($url);

        $this->assertStringStartsWith('https://www.linkedin.com/sharing/share-offsite/', $result);
        $this->assertStringContainsString('url=', $result);
    }

    #[Test]
    public function getLinkedinShareEncodesUrl(): void
    {
        $url = 'https://example.com/article?param=value';
        $result = _uho_social::getLinkedinShare($url);

        $this->assertStringContainsString(urlencode($url), $result);
    }

    // ==================== Pinterest Share Tests ====================

    #[Test]
    public function getPinterestShareReturnsValidUrl(): void
    {
        $url = 'https://example.com/article';
        $title = 'My Article Title';
        $image = 'https://example.com/image.jpg';

        $result = _uho_social::getPinterestShare($url, $title, $image);

        $this->assertStringStartsWith('https://pinterest.com/pin/create/button/', $result);
        $this->assertStringContainsString('url=', $result);
        $this->assertStringContainsString('media=', $result);
        $this->assertStringContainsString('description=', $result);
    }

    #[Test]
    public function getPinterestShareEncodesAllParameters(): void
    {
        $url = 'https://example.com/my article';
        $title = 'Article with spaces & special chars';
        $image = 'https://example.com/image name.jpg';

        $result = _uho_social::getPinterestShare($url, $title, $image);

        // All parameters should be URL encoded
        $this->assertStringNotContainsString(' ', parse_url($result, PHP_URL_QUERY) ?? '');
    }

    // ==================== Twitter/X Share Tests ====================

    #[Test]
    public function getTwitterShareReturnsValidUrl(): void
    {
        $url = 'https://example.com/article';
        $title = 'Check out this article';

        $result = _uho_social::getTwitterShare($url, $title);

        $this->assertStringStartsWith('https://www.x.com/share', $result);
        $this->assertStringContainsString('text=', $result);
        $this->assertStringContainsString('url=', $result);
    }

    #[Test]
    public function getTwitterShareEncodesText(): void
    {
        $url = 'https://example.com';
        $title = 'Title with special chars & symbols!';

        $result = _uho_social::getTwitterShare($url, $title);

        $this->assertStringContainsString(urlencode($title), $result);
    }

    #[Test]
    public function getTwitterShareHandlesEmptyTitle(): void
    {
        $url = 'https://example.com';

        $result = _uho_social::getTwitterShare($url, '');

        $this->assertStringStartsWith('https://www.x.com/share', $result);
    }

    #[Test]
    public function getTwitterShareHandlesNullTitle(): void
    {
        $url = 'https://example.com';

        $result = _uho_social::getTwitterShare($url, null);

        $this->assertStringStartsWith('https://www.x.com/share', $result);
    }

    // ==================== Email Share Tests ====================

    #[Test]
    public function getEmailShareReturnsValidMailtoUrl(): void
    {
        $url = 'https://example.com/article';
        $title = 'Article Title';

        $result = _uho_social::getEmailShare($url, $title);

        $this->assertStringStartsWith('mailto:?', $result);
        $this->assertStringContainsString('subject=', $result);
        $this->assertStringContainsString('body=', $result);
    }

    #[Test]
    public function getEmailShareIncludesUrlInBody(): void
    {
        $url = 'https://example.com/article';
        $title = 'Article Title';

        $result = _uho_social::getEmailShare($url, $title);

        $this->assertStringContainsString($url, $result);
    }

    #[Test]
    public function getEmailShareWithValidInputs(): void
    {
        $url = 'https://example.com/article';
        $title = 'Article Title';

        $result = _uho_social::getEmailShare($url, $title);

        // Function returns valid mailto URL with title and URL
        $this->assertNotNull($result);
        $this->assertStringStartsWith('mailto:?', $result);
    }

    #[Test]
    public function getEmailShareReturnsNullForInvalidInput(): void
    {
        $result = _uho_social::getEmailShare(null, null);

        $this->assertNull($result);
    }

    // ==================== Twitter Status URL Convert Tests ====================

    #[Test]
    public function twitterStatusUrlConvertConvertsHashtags(): void
    {
        $status = 'Check out #PHP and #Programming';

        $result = _uho_social::twitterStatusUrlConvert($status);

        $this->assertStringContainsString('<a href="https://twitter.com/search?q=%23PHP"', $result);
        $this->assertStringContainsString('<a href="https://twitter.com/search?q=%23Programming"', $result);
    }

    #[Test]
    public function twitterStatusUrlConvertAddsTargetBlank(): void
    {
        $status = 'Using #PHP';

        $result = _uho_social::twitterStatusUrlConvert($status, true);

        $this->assertStringContainsString('target="_blank"', $result);
    }

    #[Test]
    public function twitterStatusUrlConvertCanDisableTargetBlank(): void
    {
        $status = 'Using #PHP';

        $result = _uho_social::twitterStatusUrlConvert($status, false);

        $this->assertStringNotContainsString('target="_blank"', $result);
    }

    #[Test]
    public function twitterStatusUrlConvertPreservesNonHashtagText(): void
    {
        $status = 'Regular text without hashtags';

        $result = _uho_social::twitterStatusUrlConvert($status);

        $this->assertEquals($status, $result);
    }

    #[Test]
    public function twitterStatusUrlConvertHandlesHashtagsWithUnderscores(): void
    {
        $status = 'Check out #php_framework';

        $result = _uho_social::twitterStatusUrlConvert($status);

        $this->assertStringContainsString('#php_framework', $result);
        $this->assertStringContainsString('<a href=', $result);
    }

    // ==================== Instagram Status URL Convert Tests ====================

    #[Test]
    public function instagramStatusUrlConvertConvertsHashtags(): void
    {
        $status = 'Beautiful sunset #photography #nature';

        $result = _uho_social::instagramStatusUrlConvert($status);

        $this->assertStringContainsString('https://www.instagram.com/explore/tags/photography', $result);
        $this->assertStringContainsString('https://www.instagram.com/explore/tags/nature', $result);
    }

    #[Test]
    public function instagramStatusUrlConvertAddsTargetBlank(): void
    {
        $status = 'Using #photography';

        $result = _uho_social::instagramStatusUrlConvert($status, true);

        $this->assertStringContainsString('target="_blank"', $result);
    }

    #[Test]
    public function instagramStatusUrlConvertCanDisableTargetBlank(): void
    {
        $status = 'Using #photography';

        $result = _uho_social::instagramStatusUrlConvert($status, false);

        $this->assertStringNotContainsString('target="_blank"', $result);
    }

    // ==================== YouTube Status URL Convert Tests ====================

    #[Test]
    public function youtubeStatusUrlConvertConvertsHashtags(): void
    {
        $status = 'Watch my #tutorial #coding';

        $result = _uho_social::youtubeStatusUrlConvert($status);

        $this->assertStringContainsString('https://www.youtube.com/results?q=%23tutorial', $result);
        $this->assertStringContainsString('https://www.youtube.com/results?q=%23coding', $result);
    }

    #[Test]
    public function youtubeStatusUrlConvertAddsTargetBlank(): void
    {
        $status = 'Using #tutorial';

        $result = _uho_social::youtubeStatusUrlConvert($status, true);

        $this->assertStringContainsString('target="_blank"', $result);
    }

    #[Test]
    public function youtubeStatusUrlConvertCanDisableTargetBlank(): void
    {
        $status = 'Using #tutorial';

        $result = _uho_social::youtubeStatusUrlConvert($status, false);

        $this->assertStringNotContainsString('target="_blank"', $result);
    }

    // ==================== Edge Case Tests ====================

    #[Test]
    public function allShareMethodsHandleEmptyUrl(): void
    {
        $this->assertIsString(_uho_social::getFacebookShare(''));
        $this->assertIsString(_uho_social::getLinkedinShare(''));
        $this->assertIsString(_uho_social::getPinterestShare('', '', ''));
    }

    #[Test]
    public function shareMethodsHandleLongUrls(): void
    {
        $longUrl = 'https://example.com/' . str_repeat('a', 500);

        $fb = _uho_social::getFacebookShare($longUrl);
        $linkedin = _uho_social::getLinkedinShare($longUrl);

        $this->assertStringStartsWith('https://', $fb);
        $this->assertStringStartsWith('https://', $linkedin);
    }

    #[Test]
    public function shareMethodsHandleSpecialCharactersInUrl(): void
    {
        $specialUrl = 'https://example.com/path?query=value&other=test#anchor';

        $fb = _uho_social::getFacebookShare($specialUrl);
        $linkedin = _uho_social::getLinkedinShare($specialUrl);

        // The URL should be properly encoded
        $this->assertStringContainsString('%', $fb);
        $this->assertStringContainsString('%', $linkedin);
    }
}
