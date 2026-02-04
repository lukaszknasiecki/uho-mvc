<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Huncwot\UhoFramework\_uho_rest;

class UhoRestTest extends TestCase
{
    // ==================== HTTP Status Tests ====================

    #[Test]
    public function setHttpStatusHeaderReturns200Ok(): void
    {
        // Note: header() won't work in CLI, but we can test the return value
        $result = @_uho_rest::setHttpStatusHeader(200);

        $this->assertEquals(200, $result['code']);
        $this->assertStringContainsString('200', $result['error']);
        $this->assertStringContainsString('OK', $result['error']);
    }

    #[Test]
    public function setHttpStatusHeaderReturns404NotFound(): void
    {
        $result = @_uho_rest::setHttpStatusHeader(404);

        $this->assertEquals(404, $result['code']);
        $this->assertStringContainsString('404', $result['error']);
        $this->assertStringContainsString('Not Found', $result['error']);
    }

    #[Test]
    public function setHttpStatusHeaderReturns500InternalError(): void
    {
        $result = @_uho_rest::setHttpStatusHeader(500);

        $this->assertEquals(500, $result['code']);
        $this->assertStringContainsString('500', $result['error']);
        $this->assertStringContainsString('Internal Server Error', $result['error']);
    }

    #[Test]
    public function setHttpStatusHeaderSupportsAllCommonCodes(): void
    {
        $commonCodes = [100, 200, 201, 204, 301, 302, 400, 401, 403, 404, 405, 500, 502, 503];

        foreach ($commonCodes as $code) {
            $result = @_uho_rest::setHttpStatusHeader($code);
            $this->assertEquals($code, $result['code']);
        }
    }

    // ==================== HTTP Method Validation Tests ====================

    #[Test]
    public function validateHttpRequestMethodAcceptsAllowedMethod(): void
    {
        $result = _uho_rest::validateHttpRequestMethod('GET', ['GET', 'POST']);

        $this->assertTrue($result);
    }

    #[Test]
    public function validateHttpRequestMethodRejectsDisallowedMethod(): void
    {
        $result = _uho_rest::validateHttpRequestMethod('DELETE', ['GET', 'POST']);

        $this->assertFalse($result);
    }

    #[Test]
    public function validateHttpRequestMethodIsCaseSensitive(): void
    {
        $result = _uho_rest::validateHttpRequestMethod('get', ['GET', 'POST']);

        $this->assertFalse($result);
    }

    // ==================== Required Input Validation Tests ====================

    #[Test]
    public function validateRequiredInputPassesWithAllRequired(): void
    {
        $data = ['name' => 'John', 'email' => 'john@example.com'];
        $required = ['name', 'email'];

        $result = _uho_rest::validateRequiredInput($data, $required);

        $this->assertTrue($result);
    }

    #[Test]
    public function validateRequiredInputFailsWithMissingField(): void
    {
        $data = ['name' => 'John'];
        $required = ['name', 'email'];

        $result = _uho_rest::validateRequiredInput($data, $required);

        $this->assertFalse($result);
    }

    #[Test]
    public function validateRequiredInputFailsWithEmptyField(): void
    {
        $data = ['name' => 'John', 'email' => ''];
        $required = ['name', 'email'];

        $result = _uho_rest::validateRequiredInput($data, $required);

        $this->assertFalse($result);
    }

    #[Test]
    public function validateRequiredInputFailsWithNullData(): void
    {
        $required = ['name'];

        $result = _uho_rest::validateRequiredInput(null, $required);

        $this->assertFalse($result);
    }

    // ==================== Input Sanitization Tests ====================

    #[Test]
    public function sanitizeInputFiltersAllowedKeys(): void
    {
        $data = ['name' => 'John', 'secret' => 'hidden', 'email' => 'john@example.com'];
        $allowed = ['name' => 'string', 'email' => 'email'];

        $result = _uho_rest::sanitizeInput($data, $allowed);

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayNotHasKey('secret', $result);
    }

    #[Test]
    public function sanitizeInputSanitizesValues(): void
    {
        $data = ['name' => '<script>alert("xss")</script>John'];
        $allowed = ['name' => 'string'];

        $result = _uho_rest::sanitizeInput($data, $allowed);

        $this->assertStringNotContainsString('<script>', $result['name']);
    }

    #[Test]
    public function sanitizeInputValidatesEmail(): void
    {
        $data = ['email' => 'valid@email.com'];
        $allowed = ['email' => 'email'];

        $result = _uho_rest::sanitizeInput($data, $allowed);

        $this->assertEquals('valid@email.com', $result['email']);
    }

    // ==================== Request Validation Tests ====================

    #[Test]
    public function validateRequestReturnsErrorForInvalidMethod(): void
    {
        $data = [
            'method' => [
                'value' => 'DELETE',
                'supported' => ['GET', 'POST']
            ]
        ];

        $result = _uho_rest::validateRequest($data);

        $this->assertEquals(405, $result['header']);
        $this->assertEquals('Invalid method', $result['error']);
    }

    #[Test]
    public function validateRequestPassesForValidMethod(): void
    {
        $data = [
            'method' => [
                'value' => 'GET',
                'supported' => ['GET', 'POST']
            ]
        ];

        $result = _uho_rest::validateRequest($data);

        $this->assertArrayNotHasKey('header', $result);
        $this->assertArrayNotHasKey('error', $result);
    }

    #[Test]
    public function validateRequestSanitizesInput(): void
    {
        $data = [
            'sanitize' => [
                [
                    'value' => ['name' => 'John<script>'],
                    'supported' => ['name' => 'string']
                ]
            ]
        ];

        $result = _uho_rest::validateRequest($data);

        $this->assertStringNotContainsString('<script>', $result['sanitize'][0]['value']['name']);
    }

    #[Test]
    public function validateRequestReturnsErrorForMissingRequired(): void
    {
        $data = [
            'sanitize' => [
                [
                    'value' => ['name' => 'John'],
                    'supported' => ['name' => 'string', 'email' => 'email'],
                    'required' => ['email']
                ]
            ]
        ];

        $result = _uho_rest::validateRequest($data);

        $this->assertEquals(401, $result['header']);
        $this->assertStringContainsString('Missing required', $result['error']);
    }

    #[Test]
    public function validateRequestPassesWithAllRequiredFields(): void
    {
        $data = [
            'sanitize' => [
                [
                    'value' => ['name' => 'John', 'email' => 'john@example.com'],
                    'supported' => ['name' => 'string', 'email' => 'email'],
                    'required' => ['name', 'email']
                ]
            ]
        ];

        $result = _uho_rest::validateRequest($data);

        $this->assertArrayNotHasKey('header', $result);
    }

    // ==================== Authorization Header Tests ====================

    #[Test]
    public function getAuthorizationHeaderReturnsNullWhenNotSet(): void
    {
        // Clean up any existing values
        unset($_SERVER['Authorization']);
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $result = _uho_rest::getAuthorizationHeader();

        $this->assertNull($result);
    }

    #[Test]
    public function getAuthorizationHeaderReadsFromServer(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token-123';

        $result = _uho_rest::getAuthorizationHeader();

        $this->assertEquals('Bearer test-token-123', $result);

        // Cleanup
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    #[Test]
    public function getAuthorizationHeaderReadsFromAuthorizationKey(): void
    {
        $_SERVER['Authorization'] = 'Bearer token-from-auth';

        $result = _uho_rest::getAuthorizationHeader();

        $this->assertEquals('Bearer token-from-auth', $result);

        // Cleanup
        unset($_SERVER['Authorization']);
    }

    // ==================== Bearer Token Tests ====================

    #[Test]
    public function getBearerTokenExtractsToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer my-secret-token';

        $result = _uho_rest::getBearerToken();

        $this->assertEquals('my-secret-token', $result);

        // Cleanup
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    #[Test]
    public function getBearerTokenReturnsNullForNonBearerAuth(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        $result = _uho_rest::getBearerToken();

        $this->assertNull($result);

        // Cleanup
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    #[Test]
    public function getBearerTokenReturnsNullWhenNoAuthHeader(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['Authorization']);

        $result = _uho_rest::getBearerToken();

        $this->assertNull($result);
    }

    #[Test]
    public function getBearerTokenHandlesComplexTokens(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

        $result = _uho_rest::getBearerToken();

        $this->assertStringStartsWith('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $result);

        // Cleanup
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
}
