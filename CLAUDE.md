# Starlight Extension - Development Guidelines

This file contains instructions for AI assistants (like Claude) and human developers working on the Starlight MediaWiki extension.

## Project Overview

Starlight is a star rating and reviews extension for MediaWiki. It allows users to leave reviews for the subjects of wiki pages (products, places, organizations) rather than rating the pages themselves.

Key documentation:
- `blueprint.md` - Detailed implementation plan and architecture
- `README.md` - User-facing documentation (to be created)

---

## Git Workflow

### Branch Strategy

```
main
├── develop          # Integration branch for features
├── feature/*        # New features (feature/add-review-api)
├── bugfix/*         # Bug fixes (bugfix/fix-session-token)
├── hotfix/*         # Urgent production fixes
└── release/*        # Release preparation
```

### Commit Guidelines

**Format:**
```
<type>(<scope>): <subject>

<body>

<footer>
```

**Types:**
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style (formatting, semicolons, etc.)
- `refactor`: Code refactoring
- `test`: Adding/updating tests
- `chore`: Build process, dependencies, etc.

**Examples:**
```
feat(api): add review submission endpoint

Implements ApiReviewSubmit with validation, rate limiting,
and session token support for anonymous users.

Closes #12
```

```
fix(parser): prevent XSS in reviewer name display

Escape HTML entities in reviewer names before rendering.
Previously, names containing < or > could break layout.
```

### Commit Best Practices

1. **Atomic commits** - Each commit should represent one logical change
2. **Test before committing** - Run `composer test` before each commit
3. **No broken commits** - Every commit should leave the codebase in a working state
4. **Sign commits** - Use `git commit -s` for DCO compliance if required
5. **Never commit secrets** - No API keys, passwords, or tokens

### Pull Request Process

1. Create feature branch from `develop`
2. Make changes with atomic commits
3. Run all tests locally (`composer test`, `npm test`)
4. Run linting (`composer lint`, `npm run lint`)
5. Update documentation if needed
6. Create PR with clear description
7. Address review feedback
8. Squash and merge when approved

---

## Code Quality Standards

### PHP Coding Standards

Follow [MediaWiki PHP Coding Conventions](https://www.mediawiki.org/wiki/Manual:Coding_conventions/PHP):

```php
// Class naming: PascalCase
class ReviewValidator {

    // Property naming: camelCase with visibility
    private string $maxLength;

    // Method naming: camelCase
    public function validateReview(array $data): StatusValue {
        // Local variables: camelCase
        $reviewText = $data['text'] ?? '';

        // Constants: SCREAMING_SNAKE_CASE
        if (strlen($reviewText) > self::MAX_REVIEW_LENGTH) {
            return StatusValue::newFatal('starlight-error-too-long');
        }

        return StatusValue::newGood();
    }
}
```

**Key rules:**
- Use tabs for indentation
- Opening braces on same line for control structures
- Opening braces on new line for classes/functions
- Type declarations for parameters and return types
- No trailing whitespace
- Single blank line between methods

### JavaScript Coding Standards

Follow [MediaWiki JavaScript Coding Conventions](https://www.mediawiki.org/wiki/Manual:Coding_conventions/JavaScript):

```javascript
// Use strict mode
'use strict';

// Module pattern with IIFE
( function () {
    // camelCase for variables and functions
    const reviewForm = {
        maxLength: 2000,

        init: function () {
            this.bindEvents();
        },

        bindEvents: function () {
            // Use jQuery for DOM manipulation in MediaWiki
            $( '.starlight-submit' ).on( 'click', this.handleSubmit.bind( this ) );
        }
    };

    // Use mw.hook for initialization
    mw.hook( 'wikipage.content' ).add( function () {
        reviewForm.init();
    } );
}() );
```

### SQL/Database Standards

- Use MediaWiki's database abstraction layer
- Never concatenate SQL strings
- Use parameterized queries via `IDatabase` methods
- Prefix all tables with `starlight_`

```php
// Good
$dbw->newInsertQueryBuilder()
    ->insertInto('starlight_review')
    ->row([
        'sr_page_id' => $pageId,
        'sr_review_text' => $text,
    ])
    ->caller(__METHOD__)
    ->execute();

// Bad - never do this
$dbw->query("INSERT INTO starlight_review VALUES ($pageId, '$text')");
```

---

## Testing Requirements

### Before Every Commit

```bash
# Run PHP tests
composer test

# Run PHP linting
composer lint

# Run JavaScript tests (if applicable)
npm test

# Run JavaScript linting
npm run lint
```

### Test Coverage Goals

- **Unit tests**: All validators, formatters, and utilities
- **Integration tests**: API endpoints, database operations
- **Parser tests**: Tag parsing and output

### Writing Tests

```php
// tests/phpunit/Unit/ReviewValidatorTest.php
namespace MediaWiki\Extension\Starlight\Tests\Unit;

use MediaWiki\Extension\Starlight\ReviewValidator;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Starlight\ReviewValidator
 */
class ReviewValidatorTest extends MediaWikiUnitTestCase {

    public function testValidateRatingRejectsOutOfRange(): void {
        $validator = new ReviewValidator($this->getConfig());

        $result = $validator->validateRating(6);

        $this->assertFalse($result->isGood());
        $this->assertTrue($result->hasMessage('starlight-error-invalid-rating'));
    }

    public function provideValidRatings(): array {
        return [
            'minimum' => [1],
            'middle' => [3],
            'maximum' => [5],
        ];
    }

    /**
     * @dataProvider provideValidRatings
     */
    public function testValidateRatingAcceptsValidValues(int $rating): void {
        $validator = new ReviewValidator($this->getConfig());

        $result = $validator->validateRating($rating);

        $this->assertTrue($result->isGood());
    }
}
```

---

## Security Checklist

Before submitting any code that handles user input:

- [ ] **XSS**: Is all output properly escaped?
  - Use `htmlspecialchars()` or `Xml::element()`
  - Never use `innerHTML` with user data in JS
- [ ] **CSRF**: Do write operations require tokens?
  - API write endpoints need `needsToken()` returning `'csrf'`
- [ ] **SQL Injection**: Using parameterized queries?
  - Always use `IDatabase` methods, never raw SQL
- [ ] **Authorization**: Are permissions checked?
  - Use `$this->getAuthority()->isAllowed('right-name')`
- [ ] **Rate Limiting**: Is abuse prevented?
  - Check rate limits before processing
- [ ] **Input Validation**: Is all input validated server-side?
  - Never trust client-side validation alone

---

## File Organization

```
src/
├── Starlight.php           # Main class, service container
├── Review.php              # Review data model (immutable)
├── ReviewStore.php         # Database operations (CRUD)
├── ReviewValidator.php     # Input validation
├── ReviewFormatter.php     # HTML rendering
├── SessionManager.php      # Cookie/session handling
│
├── Hooks/                  # MediaWiki hook handlers
│   ├── ParserHooks.php     # Parser tag registration
│   ├── PageHooks.php       # Page lifecycle hooks
│   └── SchemaHooks.php     # Database schema updates
│
├── Api/                    # API endpoint handlers
│   ├── ApiReviewSubmit.php
│   ├── ApiReviewEdit.php
│   └── ...
│
└── Special/                # Special page handlers
    ├── SpecialReviews.php
    └── SpecialManageReviews.php
```

**Naming conventions:**
- One class per file
- Filename matches class name
- Namespace matches directory structure

---

## Internationalization (i18n)

All user-facing text must be internationalized:

```php
// In PHP
$this->msg('starlight-review-count', $count)->text();

// For HTML output
$this->msg('starlight-review-count', $count)->escaped();

// With parameters
$this->msg('starlight-rating-summary')
    ->params($pageName, $avgRating, $count)
    ->parse();
```

**Message keys:**
- Prefix all keys with `starlight-`
- Use lowercase with hyphens
- Be descriptive: `starlight-error-review-too-long`

**i18n/en.json structure:**
```json
{
    "@metadata": {
        "authors": ["Your Name"]
    },
    "starlight-desc": "Adds star ratings and reviews to wiki pages",
    "starlight-review-count": "{{PLURAL:$1|$1 review|$1 reviews}}",
    "starlight-error-too-long": "Review text exceeds maximum length of $1 characters"
}
```

---

## Accessibility Requirements

All UI components must be accessible:

### Keyboard Navigation
- All interactive elements must be focusable
- Tab order must be logical
- Custom widgets need keyboard handlers

### Screen Readers
```html
<!-- Good: Proper labeling -->
<div class="starlight-stars" role="radiogroup" aria-label="Rating">
    <input type="radio" id="star1" name="rating" value="1">
    <label for="star1">1 star</label>
</div>

<!-- Good: Live regions for dynamic content -->
<div aria-live="polite" class="starlight-message"></div>
```

### Color and Contrast
- Don't rely on color alone to convey information
- Text must have 4.5:1 contrast ratio (WCAG AA)
- Focus indicators must be visible

---

## Performance Guidelines

### Database Queries
- Use indexes for frequently queried columns
- Batch queries when possible (LinkBatchFactory pattern)
- Cache aggregate data (page stats table)

### Caching
```php
// Use parser cache appropriately
$parser->getOutput()->updateCacheExpiry(0); // Disable for dynamic content

// Or set reasonable expiry
$parser->getOutput()->updateCacheExpiry(300); // 5 minutes
```

### ResourceLoader
- Minimize JS/CSS bundle size
- Use dependencies correctly
- Lazy-load when possible

---

## Common Pitfalls

### Don't Do This

```php
// Bad: Direct database access without abstraction
$mysqli->query("SELECT * FROM starlight_review");

// Bad: Unescaped output
echo "<p>" . $userInput . "</p>";

// Bad: Hardcoded text
$output->addHTML('<p>No reviews found</p>');

// Bad: Using $_GET/$_POST directly
$pageId = $_GET['pageid'];
```

### Do This Instead

```php
// Good: Use MediaWiki database abstraction
$dbr->newSelectQueryBuilder()
    ->select('*')
    ->from('starlight_review')
    ->caller(__METHOD__)
    ->fetchResultSet();

// Good: Escape output
$output->addHTML(Html::element('p', [], $userInput));

// Good: Use i18n
$output->addWikiMsg('starlight-no-reviews');

// Good: Use WebRequest
$pageId = $this->getRequest()->getInt('pageid');
```

---

## Getting Help

- **MediaWiki docs**: https://www.mediawiki.org/wiki/Developer_hub
- **Extension development**: https://www.mediawiki.org/wiki/Developing_extensions
- **Coding conventions**: https://www.mediawiki.org/wiki/Manual:Coding_conventions
- **API documentation**: https://www.mediawiki.org/wiki/API:Main_page

---

## Release Checklist

Before releasing a new version:

- [ ] All tests pass
- [ ] No linting errors
- [ ] CHANGELOG.md updated
- [ ] Version bumped in extension.json
- [ ] README.md reflects current features
- [ ] Database migrations tested (upgrade and fresh install)
- [ ] Accessibility audit completed
- [ ] Security review completed
- [ ] i18n messages complete with qqq documentation
