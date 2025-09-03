# SEO Monitoring Platform - Database Architecture & Multi-Tenancy Strategy

## Overview

This document outlines the comprehensive database schema and multi-tenant architecture for the SEO Monitoring Platform, designed to handle large-scale SEO data with proper tenant isolation, high performance, and scalability.

## Multi-Tenancy Strategy

### Approach: Row-Level Tenancy (RLS)
We've implemented a **row-level multi-tenancy** strategy where:
- All tenant data resides in shared tables with `tenant_id` columns
- Data isolation is enforced at the application and database level
- Global scoping ensures automatic tenant filtering
- Cost-effective and easily scalable approach

### Key Components

#### 1. Tenant Isolation Mechanism
- **BelongsToTenant Trait**: Automatic scoping and tenant_id injection
- **Global Scopes**: Query-level filtering by authenticated user's tenant
- **Foreign Key Constraints**: Database-level referential integrity
- **Manual Override Methods**: For admin operations and cross-tenant queries

#### 2. Security Features
- Automatic tenant_id assignment on record creation
- Global scopes prevent accidental cross-tenant data access
- Method-level permissions based on user roles
- Audit trails for all tenant operations

## Database Schema Design

### Core Entities

#### Tenants Table
```sql
-- Multi-tenant foundation with subscription management
tenants:
  - id (Primary Key)
  - uuid (Unique identifier for external references)
  - name, slug, domain (Tenant identification)
  - settings, branding (JSON configuration)
  - plan, max_* (Subscription limits)
  - trial/subscription timestamps
  - Soft deletes for data retention
```

**Design Decisions:**
- UUID for external API references (security)
- JSON settings for flexible configuration
- Plan-based limitations built into schema
- Soft deletes preserve audit trails

#### Users Table
```sql
-- Multi-role user management with tenant scoping
users:
  - tenant_id (Foreign Key - CASCADE DELETE)
  - role enum (owner, admin, manager, viewer)
  - permissions JSON (granular access control)
  - preferences JSON (UI/UX personalization)
  - timezone, language (localization)
  - Unique constraint on [tenant_id, email]
```

**Design Decisions:**
- Role-based access with JSON permissions for flexibility
- Tenant-scoped email uniqueness (users can exist across tenants)
- Cascade delete maintains referential integrity
- Localization support built-in

### SEO Data Architecture

#### Projects Table
```sql
-- Website/domain tracking containers
projects:
  - tenant_id, user_id (ownership & scoping)
  - url, domain (website identification)
  - target_countries, target_languages (JSON arrays)
  - search_engines, devices (tracking preferences)
  - integrations JSON (GSC, GA4 connections)
  - last_crawled_at, last_positions_updated_at (status tracking)
```

**Design Decisions:**
- Domain extraction for efficient filtering
- JSON arrays for multi-target tracking
- Integration settings stored as flexible JSON
- Timestamp tracking for data freshness monitoring

#### Keywords Table
```sql
-- Keyword tracking with rich metadata
keywords:
  - tenant_id, project_id (hierarchy & scoping)
  - keyword, keyword_hash (deduplication)
  - priority enum, categories JSON (organization)
  - intent enum (search intent classification)
  - country, language, location (geo-targeting)
  - search_volume, difficulty_score, cpc (SEO metrics)
  - current_position, previous_position (position caching)
  - related_keywords JSON (semantic relationships)
```

**Design Decisions:**
- MD5 hash for efficient deduplication
- Position caching for quick dashboard queries
- Intent classification for content strategy
- Composite unique constraint prevents duplicates
- Rich indexing for performance optimization

#### Keyword Positions Table (High-Volume)
```sql
-- Daily position tracking (millions of records)
keyword_positions:
  - tenant_id, keyword_id (hierarchy)
  - date, search_engine, device (tracking dimensions)
  - position, url (ranking data)
  - serp_features JSON (rich SERP data)
  - estimated_traffic, estimated_value (calculations)
  - SERP appearance flags (featured_snippet, local_pack, etc.)
```

**Critical Performance Optimizations:**
- Composite unique constraint prevents duplicate entries
- Strategic indexing for common query patterns
- Date partitioning ready (PostgreSQL)
- Estimated values pre-calculated for dashboard speed
- Minimal nullable fields to reduce storage

### Supporting Tables

#### SERP Features Table
```sql
-- Track special SERP elements
serp_features:
  - feature_type enum (featured_snippet, local_pack, etc.)
  - domain ownership tracking
  - Rich feature-specific data in JSON
  - Position tracking for feature placement
```

#### Competitors Table
```sql
-- Competitive analysis data
competitors:
  - Domain-based competitor tracking
  - Authority metrics (DA, backlinks)
  - Traffic and visibility estimates
  - Shared keyword analysis
  - Priority-based monitoring
```

#### Reports & Notifications
```sql
reports:
  - Automated report generation
  - Multi-format support (PDF, Excel, HTML)
  - Scheduling and delivery management
  - Status tracking and error handling

notifications:
  - Multi-channel alert system
  - Severity-based prioritization
  - Delivery status tracking
  - Rich contextual data
```

## Performance Optimizations

### Indexing Strategy

#### High-Frequency Query Indexes
```sql
-- Tenant-scoped queries (most common)
INDEX idx_tenant_active ON keywords (tenant_id, is_tracking_active);
INDEX idx_tenant_date ON keyword_positions (tenant_id, date);

-- Dashboard performance
INDEX idx_project_position ON keywords (project_id, current_position);
INDEX idx_keyword_date ON keyword_positions (keyword_id, date);

-- Analytics queries
INDEX idx_date_position ON keyword_positions (date, position);
INDEX idx_tenant_date_position ON keyword_positions (tenant_id, date, position);
```

#### Composite Indexes for Complex Queries
- Multi-column indexes for tenant + business logic filters
- Covering indexes for read-heavy dashboard queries
- Partial indexes for boolean flags and enums

### Large Table Management

#### Keyword Positions Table (High-Volume Strategy)
1. **Partitioning Ready**: Schema supports PostgreSQL date partitioning
2. **Archival Strategy**: Automated archival of old position data
3. **Batch Processing**: Optimized for bulk daily inserts
4. **Query Optimization**: Indexes designed for date-range queries

#### Estimated Values Pre-calculation
- Traffic and value calculations stored vs. calculated
- Reduces real-time computation load
- Updated during batch position processing

## Data Integrity & Constraints

### Foreign Key Relationships
```sql
-- Cascade deletes for data consistency
tenant -> users, projects, keywords (CASCADE)
project -> keywords, competitors, reports (CASCADE)
keyword -> positions, serp_features (CASCADE)

-- Null on delete for optional relationships
user -> reports (NULL ON DELETE)
```

### Business Logic Constraints
- Unique constraints prevent data duplication
- Check constraints ensure data validity
- Enum values standardize categorical data

### Soft Deletes Strategy
- Tenants, Users, Projects: Soft deleted for audit trails
- Keywords: Soft deleted to preserve historical data
- Positions: Hard deleted (archival-based retention)

## Scalability Considerations

### Horizontal Scaling Readiness
1. **Database Sharding**: Tenant-based sharding possible
2. **Read Replicas**: Separate analytics from operational queries  
3. **Connection Pooling**: Optimized for high-concurrency access
4. **Caching Strategy**: Redis integration points identified

### Storage Optimization
- Efficient data types (enums vs strings)
- JSON for flexible but structured data
- Timestamp precision appropriate to use case
- Strategic use of nullable vs default values

### Query Performance
- Tenant-scoped queries prevent full table scans
- Strategic denormalization (position caching)
- Batch processing patterns for heavy operations

## Security Implementation

### Access Control
```php
// Automatic tenant scoping via BelongsToTenant trait
BelongsToTenant::bootBelongsToTenant() {
    // Global scope adds WHERE tenant_id = auth()->user()->tenant_id
    // Automatic tenant_id injection on create
}
```

### Data Protection
- Tenant isolation prevents data leakage
- Role-based permissions at model level
- Audit trails through Laravel's built-in features
- SQL injection protection via Eloquent ORM

## Queue System Integration

### Background Processing Architecture
```php
// Redis-based queue system for:
- Daily position tracking jobs
- Report generation processes  
- Notification delivery
- Data analysis computations
- Competitor monitoring
```

### Job Design Patterns
- Tenant-aware job classes
- Batch processing for efficiency
- Retry mechanisms for reliability
- Progress tracking for long-running tasks

## Monitoring & Observability

### Performance Metrics
- Query performance monitoring
- Index usage analysis
- Tenant growth tracking
- Storage utilization monitoring

### Business Metrics
- Position tracking accuracy
- Report generation success rates
- User engagement analytics
- System reliability metrics

## Migration & Deployment Strategy

### Schema Evolution
- Backwards-compatible migrations
- Zero-downtime deployment ready
- Data migration procedures documented
- Rollback strategies for each migration

### Production Considerations
- Connection pool sizing
- Backup and recovery procedures
- Monitoring and alerting setup
- Performance baseline establishment

---

This architecture provides a solid foundation for scaling to handle millions of keyword positions while maintaining excellent performance and complete tenant isolation.