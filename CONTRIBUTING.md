# Contributing to Inngest PHP SDK

Thank you for your interest in contributing to the Inngest PHP SDK!

## Development Setup

1. **Clone the repository**
   ```bash
   git clone https://github.com/inngest/inngest-php-sdk.git
   cd inngest-php-sdk
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Set up environment variables**
   ```bash
   cp .env.example .env
   # Edit .env with your Inngest credentials
   ```

## Coding Standards

This project follows these conventions:

- **PSR-12** coding standard
- **snake_case** for variables and properties
- **camelCase** for method names
- **PascalCase** for class names
- **protected** visibility by default (not private unless necessary)
- Complete **PHPDoc blocks** for all public methods

### Example:

```php
<?php

declare(strict_types=1);

namespace DealNews\Inngest\Example;

/**
 * Example class demonstrating coding standards
 */
class ExampleClass
{
    /**
     * @param string $user_id The user's unique identifier
     * @param array<string, mixed> $user_data Additional user data
     */
    protected function __construct(
        protected string $user_id,
        protected array $user_data = []
    ) {
    }

    /**
     * Process the user data
     *
     * @return array<string, mixed>
     */
    public function processData(): array
    {
        $processed_data = [];
        
        foreach ($this->user_data as $key => $value) {
            $processed_data[$key] = $this->transformValue($value);
        }
        
        return $processed_data;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected function transformValue(mixed $value): mixed
    {
        return $value;
    }
}
```

## Testing

### Running Tests

```bash
# Run all tests
composer test

# Run specific test file
vendor/bin/phpunit tests/Unit/EventTest.php

# Run with coverage
vendor/bin/phpunit --coverage-html coverage
```

### Writing Tests

- Place unit tests in `tests/Unit/`
- Place integration tests in `tests/Integration/`
- Use descriptive test method names that explain what is being tested
- Follow the Arrange-Act-Assert pattern

Example:

```php
public function testEventCreatesWithDefaultTimestamp(): void
{
    // Arrange
    $before = (int) (microtime(true) * 1000);
    
    // Act
    $event = new Event('test/event', []);
    
    // Assert
    $this->assertGreaterThanOrEqual($before, $event->getTs());
}
```

## Pull Request Process

1. **Fork the repository** and create your branch from `main`
   ```bash
   git checkout -b feature/my-new-feature
   ```

2. **Make your changes** following the coding standards

3. **Add tests** for any new functionality

4. **Update documentation** if needed (README.md, PHPDoc comments)

5. **Run tests and linting**
   ```bash
   composer test
   vendor/bin/phpstan analyse
   vendor/bin/phpcs
   ```

6. **Commit your changes** with a clear commit message
   ```bash
   git commit -m "feat: add new feature X"
   ```

7. **Push to your fork** and submit a pull request
   ```bash
   git push origin feature/my-new-feature
   ```

## Commit Message Format

We follow conventional commits:

- `feat:` - New feature
- `fix:` - Bug fix
- `docs:` - Documentation changes
- `test:` - Adding or updating tests
- `refactor:` - Code refactoring
- `perf:` - Performance improvements
- `chore:` - Maintenance tasks

Examples:
```
feat: add support for batch events
fix: correct signature verification for dev mode
docs: update README with step examples
test: add unit tests for StepContext
```

## Architecture Guidelines

### Key Principles

1. **Follow the SDK Specification**: Adhere to the [Inngest SDK Spec](https://github.com/inngest/inngest/blob/main/docs/SDK_SPEC.md)

2. **Minimize Dependencies**: Keep external dependencies to a minimum

3. **Type Safety**: Use strict typing (`declare(strict_types=1)`)

4. **Error Handling**: Provide clear error messages and appropriate exception types

5. **Testability**: Write code that is easy to test in isolation

### Directory Structure

```
src/
├── Client/         # Main Inngest client
├── Config/         # Configuration management
├── Error/          # Exception classes
├── Event/          # Event-related classes
├── Function/       # Function definitions and triggers
├── Http/           # HTTP handling and signature verification
├── Middleware/     # Middleware support (future)
└── Step/           # Step execution engine
```

## Questions or Issues?

- Open an issue for bugs or feature requests
- Check existing issues before creating new ones
- For questions, use GitHub Discussions

## License

By contributing, you agree that your contributions will be licensed under the Apache 2.0 License.
