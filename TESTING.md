# SEO Dashboard Testing Suite

This document provides comprehensive information about the testing strategy and implementation for the SEO Dashboard platform.

## Overview

The SEO Dashboard uses a comprehensive testing approach with multiple test types to ensure reliability, security, and performance. The test suite is built using Pest PHP and covers all aspects of the multi-tenant SEO monitoring platform.

## Test Structure

```
/tests/
├── Unit/                     # Unit tests for models, services, and calculations
│   ├── Models/              # Model relationships and business logic
│   ├── Services/            # Service layer and calculations
│   └── Calculations/        # SEO metric calculations
├── Feature/                 # Feature tests for API endpoints and workflows
│   ├── Auth/               # Authentication and authorization
│   ├── Api/                # RESTful API endpoints
│   ├── Projects/           # Project management features
│   ├── Keywords/           # Keyword tracking features
│   └── Reports/            # Report generation features
├── Integration/            # Integration tests for external services
│   ├── ExternalApis/       # Third-party API integrations
│   ├── Notifications/      # Email and push notifications
│   └── Jobs/               # Queue job processing
├── Performance/            # Performance and load testing
│   ├── Database/          # Database query optimization
│   └── Api/               # API response time testing
└── Feature/Security/       # Security and multi-tenancy isolation
```

## Test Categories

### Unit Tests (120+ tests)

**Model Tests:**
- User model relationships and role management
- Tenant model with plan limitations and subscriptions
- Project model with analytics calculations
- Keyword model with position tracking and SEO metrics
- KeywordPosition model with trend calculations

**Service Tests:**
- SEOCalculationService with comprehensive metric calculations
- External API service mocking and error handling
- Business logic validation and edge cases

### Feature Tests (80+ tests)

**API Endpoint Testing:**
- Project CRUD operations with multi-tenant security
- Keyword management and bulk operations
- Dashboard data aggregation and real-time metrics
- Report generation and scheduling
- User management and permission systems

**Authentication & Authorization:**
- Multi-tenant user authentication
- Role-based access control (RBAC)
- API token management with Laravel Sanctum
- Permission inheritance and tenant isolation

### Integration Tests (40+ tests)

**External API Integration:**
- SERP API position tracking with retry logic
- Google Search Console data integration
- Google Analytics 4 traffic data
- Error handling and rate limiting

**Queue Job Processing:**
- Keyword position tracking jobs
- Report generation workflows
- Email notification dispatching
- Bulk data processing operations

### Security Tests (50+ tests)

**Multi-Tenant Isolation:**
- Complete data segregation between tenants
- API endpoint security validation
- Database-level tenant constraints
- Cross-tenant access prevention

**Data Security:**
- SQL injection prevention
- XSS protection validation
- CSRF token verification
- Input sanitization testing

### Performance Tests (30+ tests)

**API Performance:**
- Response time optimization (< 2 seconds for complex queries)
- Database query efficiency (< 10 queries for dashboard)
- Large dataset handling (10,000+ records)
- Concurrent user support (50+ simultaneous users)

**Memory Management:**
- Memory usage optimization
- Pagination efficiency
- Large dataset processing

## Configuration

### Test Environment Setup

The test suite uses PostgreSQL as the primary database for accurate production simulation:

```xml
<!-- phpunit.xml -->
<env name="DB_CONNECTION" value="pgsql_testing"/>
<env name="DB_DATABASE" value="seo_dashboard_testing"/>
<env name="DB_USERNAME" value="postgres"/>
<env name="DB_PASSWORD" value=""/>
```

### External API Mocking

External services are mocked during testing to ensure reliable and fast test execution:

```php
// Mock configuration
<env name="SERP_API_MOCK" value="true"/>
<env name="GOOGLE_API_MOCK" value="true"/>
<env name="ANALYTICS_API_MOCK" value="true"/>
```

## Factory System

Comprehensive model factories provide realistic test data:

- **UserFactory**: Creates users with various roles and permissions
- **TenantFactory**: Generates tenants with different plans and limits
- **ProjectFactory**: Creates SEO projects with realistic configurations
- **KeywordFactory**: Generates keywords with position data and metrics
- **KeywordPositionFactory**: Creates position history with trends
- **CompetitorFactory**: Generates competitor analysis data
- **ReportFactory**: Creates various report types and schedules
- **NotificationFactory**: Generates different notification types

## Test Helpers

### Custom Expectations

```php
expect($position)->toHaveValidPosition(); // 1-100 range
expect($email)->toBeValidEmail();         // Email format validation
expect($url)->toBeValidUrl();            // URL format validation
```

### Testing Utilities

```php
// Multi-tenant helpers
actingAsTenantUser($attributes);
createAuthenticatedUser($userAttr, $tenantAttr);

// Performance helpers
$this->assertQueryCountLessThan(10, $callback);
$this->assertResponseTimeLessThan(2000, $callback);

// Security helpers
$this->assertTenantIsolation($user, $model, $data);
mockExternalApis();
```

## Running Tests

### Local Development

```bash
# Run all tests
./vendor/bin/pest

# Run specific test suites
./vendor/bin/pest --testsuite=Unit
./vendor/bin/pest --testsuite=Feature
./vendor/bin/pest --testsuite=Integration
./vendor/bin/pest --testsuite=Performance

# Run with coverage
./vendor/bin/pest --coverage --min=80

# Run parallel execution
./vendor/bin/pest --parallel
```

### Continuous Integration

The GitHub Actions workflow automatically runs:

1. **Matrix Testing**: PHP 8.2 & 8.3 with different dependency versions
2. **Service Dependencies**: PostgreSQL 15 and Redis 7
3. **Test Suites**: Unit, Feature, Integration, Performance, Security
4. **Coverage Reporting**: Codecov integration with 80% minimum coverage
5. **Security Scanning**: Laravel security audit

## Performance Benchmarks

### API Response Times
- Project listing: < 1 second (500+ projects)
- Dashboard data: < 3 seconds (100+ keywords with history)
- Keyword filtering: < 2 seconds (5000+ keywords)
- Report generation: < 10 seconds (1000+ keywords, 90 days)

### Database Performance
- Query count optimization: < 10 queries for complex operations
- Memory usage: < 20MB for large dataset operations
- Concurrent users: 50+ simultaneous without degradation

### Coverage Requirements
- Overall coverage: 80% minimum
- Critical paths: 95% minimum coverage
- Business logic: 90+ minimum coverage

## Test Data Management

### Database Seeding
```php
// Create realistic test scenarios
$this->createSampleSeoData($tenant);

// Multi-tenant data isolation
$this->createTenantWithUsers($userCount, $tenantAttrs, $userAttrs);
```

### External API Mocking
```php
// Comprehensive API response simulation
Http::fake([
    'serpapi.com/*' => Http::response($serpData, 200),
    'googleapis.com/*' => Http::response($analyticsData, 200)
]);
```

## Debugging Tests

### Verbose Output
```bash
# Detailed test output
./vendor/bin/pest --verbose

# Stop on first failure
./vendor/bin/pest --stop-on-failure

# Filter specific tests
./vendor/bin/pest --filter="User Model"
```

### Database Inspection
```php
// Debug database state
$this->assertDatabaseHas('keywords', ['keyword' => 'seo tools']);
$this->assertDatabaseMissing('projects', ['tenant_id' => $otherTenant->id]);
```

## Contributing to Tests

### Writing New Tests
1. Follow the existing structure and naming conventions
2. Include both positive and negative test cases
3. Test edge cases and error conditions
4. Ensure multi-tenant isolation where applicable
5. Add performance assertions for database operations

### Test Coverage
- All new features must include comprehensive tests
- Aim for 90%+ coverage on new code
- Include integration tests for external dependencies
- Add security tests for tenant-sensitive operations

## Security Considerations

### Multi-Tenant Testing
- Always test cross-tenant data access prevention
- Verify API endpoints respect tenant boundaries
- Validate database constraints prevent data leakage
- Test role-based permissions within tenant context

### Data Protection
- Sanitize sensitive data in test outputs
- Use factory data instead of real credentials
- Mock all external API calls to prevent data leakage
- Verify encryption and hashing implementations

## Monitoring and Reporting

### Coverage Reports
- HTML reports generated in `coverage-report/`
- Clover XML for CI integration
- Codecov integration for pull request analysis

### Performance Metrics
- Response time tracking across test runs
- Memory usage monitoring for large operations
- Query count optimization verification

### CI/CD Integration
- Automated testing on all pull requests
- Deployment blocking on test failures
- Performance regression detection
- Security vulnerability scanning

This comprehensive test suite ensures the SEO Dashboard platform maintains high quality, security, and performance standards across all features and integrations.