# LCMS SDK

A PHP MVC framework for building content-managed applications powered by the Logical Content Management System (LCMS).

## Overview

The LCMS SDK provides a complete application framework with routing, controllers, views, database integration, multi-language support, and seamless integration with LCMS cloud services.

## Requirements

- PHP >= 8.1
- Composer

## Installation

```bash
composer require appitudeio/lcms-sdk
```

## Core Components

### Application Kernel (Backbone)
- **Request/Response** - HTTP handling with middleware support
- **Routing** - Pattern matching, named routes, localization
- **Kernel** - Application bootstrapper with event system

### MVC Architecture
- **Controllers** - Base controller with before/after hooks
- **Views** - Template rendering with data binding
- **Pages** - Page compilation and metadata management

### Content Management
- **Node System** - Dynamic content blocks (TEXT, HTML, IMAGE, LOOP, etc.)
- **Template Engine** - HTML parsing and rendering
- **Database** - PDO wrapper with query builder
- **SEO** - Meta tags, OpenGraph, Twitter Cards, JSON-LD

### Internationalization
- **Locale** - Multi-language support with currency, timezone handling
- **Translator** - i18n translation system
- **Navigation** - Localized navigation management

### Integration
- **API Client** - LCMS cloud services integration (sandbox/production)
- **Asset Management** - File uploads with validation
- **Storage** - CDN integration via LCMS Storage

### Utilities
- **DI Container** - PHP-DI dependency injection
- **Cache** - Caching layer
- **Logger** - Logging functionality
- **Env** - Environment configuration
- **Crypt** - Encryption utilities
- **Recaptcha/Akismet** - Spam protection

## Basic Usage

### Routing

```php
use LCMS\Route;

Route::get('/', 'HomeController@index');
Route::get('/about', 'PageController@show');
Route::post('/contact', 'ContactController@submit');
```

### Controllers

```php
use LCMS\Controller;

class HomeController extends Controller {
    public function index() {
        return $this->view('home', [
            'title' => 'Welcome'
        ]);
    }
}
```

### Database

```php
use LCMS\Database;

$users = Database::table('users')
    ->where('active', true)
    ->get();
```

### Multi-language

```php
use LCMS\Translator;

Translator::set('welcome', 'Welcome', 'en');
Translator::set('welcome', 'Välkommen', 'sv');

echo Translator::get('welcome'); // Outputs based on current locale
```

## Architecture

The SDK is designed to work with LCMS cloud services while providing flexibility for custom implementations. Applications built with the SDK can leverage centralized content management, multi-site support, and CDN delivery.

## Dependencies

- [Guzzle HTTP](https://github.com/guzzle/guzzle) - HTTP client
- [PHP-DI](https://php-di.org/) - Dependency injection
- [LCMS Storage](https://packagist.org/packages/appitudeio/lcms-storage) - Asset storage

## License

Proprietary. See [LICENSE.md](LICENSE.md) for details.

Copyright (c) 2025 Appitude AB - All rights reserved.

## Support

For licensing and support inquiries: support@appitudeio.com
