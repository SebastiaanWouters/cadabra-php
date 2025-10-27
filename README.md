# Cadabra PHP Client & Symfony Bundle

**Opt-in query caching** for Symfony applications with Doctrine ORM. Intercepts at DBAL level for transparent integration.

> **Note**: This package requires a running [Cadabra server](https://github.com/SebastiaanWouters/cadabra). The server handles SQL normalization, cache key generation, and invalidation logic.

## Why This Works

This bundle intercepts database queries **after SQL generation but before execution** (DBAL `Statement::execute` level). It caches **raw database arrays before ORM hydration**, allowing Doctrine to hydrate entities normally. All Doctrine features work: UnitOfWork, lazy loading, lifecycle events, etc.

## Key Design Principles

**1. Opt-In Caching**: Queries are **NOT cached by default**. You explicitly mark queries for caching using `->useCadabraCache()` or the `/* CADABRA:USE */` comment. This keeps cache size low and gives you full control.

**2. No Logic Duplication**: This client sends raw SQL to the Cadabra server without any normalization. The server handles all SQL normalization, cache key generation, and invalidation logic. This ensures consistent behavior across all clients (PHP, TypeScript, etc.).

**3. Automatic Invalidation**: Write queries (INSERT/UPDATE/DELETE) **always** trigger invalidation - no configuration needed. The server intelligently determines which cache entries to invalidate.

### The Architecture

```
User Code
   ↓
Doctrine Repository (find/createQueryBuilder/etc.)
   ↓
Doctrine ORM (generates DQL)
   ↓
Doctrine DBAL (converts to SQL: "SELECT t0.id FROM users t0 WHERE t0.id = ?")
   ↓
🎯 CadabraMiddleware (intercepts here)
   ├─ Check for /* CADABRA:USE */ comment
   ├─ If NO comment → Execute directly (no caching)
   ├─ If YES → Send RAW SQL to Cadabra server for analysis
   ├─ Server normalizes (t0 → u) and generates cache key fingerprint
   ├─ Check server cache by fingerprint
   ├─ Return CachedResult if hit
   └─ Execute & register with server if miss
   ↓
Database (on cache miss or when not using Cadabra)
   ↓
Doctrine ORM (hydrates entities from cached arrays)
   ↓
Your Entity Objects
```

## Installation

```bash
composer require cadabra/php
```

## Quick Start

### 1. Register the Bundle

```php
// config/bundles.php
return [
    // ...
    Cadabra\SymfonyBundle\CadabraBundle::class => ['all' => true],
];
```

### 2. Configure Cadabra

```yaml
# config/packages/cadabra.yaml
cadabra:
    service_url: '%env(CADABRA_SERVICE_URL)%'  # Required
    prefix: '%env(APP_ENV)%_myapp'              # Optional, default: 'cadabra'
```

### 3. Set Environment Variables

```env
# .env
CADABRA_SERVICE_URL=http://localhost:6942
```

### 4. Integrate CadabraQueryBuilder (Recommended)

Create a base repository class that returns `CadabraQueryBuilder` instead of the default `QueryBuilder`:

```php
// src/Repository/CadabraRepository.php
namespace App\Repository;

use Cadabra\SymfonyBundle\ORM\CadabraQueryBuilder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

abstract class CadabraRepository extends ServiceEntityRepository
{
    public function createQueryBuilder($alias, $indexBy = null): CadabraQueryBuilder
    {
        return (new CadabraQueryBuilder($this->getEntityManager()))
            ->select($alias)
            ->from($this->getEntityName(), $alias, $indexBy);
    }
}
```

Then extend it in your repositories:

```php
// src/Repository/UserRepository.php
namespace App\Repository;

use App\Entity\User;

class UserRepository extends CadabraRepository
{
    // Now createQueryBuilder() returns CadabraQueryBuilder
    // which has ->useCadabraCache() method available
}
```

### 5. Mark Queries for Caching

```php
// Enable caching for specific queries
$users = $repository->createQueryBuilder('u')
    ->where('u.status = :status')
    ->setParameter('status', 'active')
    ->useCadabraCache()  // ← Opt-in to caching
    ->getQuery()
    ->getResult();
```

## Usage

### Opt-In Caching with QueryBuilder

**Method 1: Using CadabraQueryBuilder (Recommended)**

```php
// After integrating CadabraRepository (see Quick Start)
public function findActiveUsers(): array
{
    return $this->createQueryBuilder('u')
        ->where('u.status = :status')
        ->setParameter('status', 'active')
        ->useCadabraCache()  // ← Enable caching
        ->getQuery()
        ->getResult();
}

// Queries without ->useCadabraCache() are NOT cached
public function findUserForUpdate(int $id): ?User
{
    return $this->createQueryBuilder('u')
        ->where('u.id = :id')
        ->setParameter('id', $id)
        // No ->useCadabraCache() = no caching (good for transactions)
        ->getQuery()
        ->getOneOrNullResult();
}
```

**Method 2: Using Trait in Custom QueryBuilder**

If you already have a custom QueryBuilder:

```php
namespace App\ORM;

use Cadabra\SymfonyBundle\ORM\CadabraQueryBuilderTrait;
use Doctrine\ORM\QueryBuilder;

class MyCustomQueryBuilder extends QueryBuilder
{
    use CadabraQueryBuilderTrait;

    // Your custom methods here
}
```

**Method 3: Using Magic Comment (Raw SQL)**

```php
// Cache this query
$sql = '/* CADABRA:USE */ SELECT * FROM users WHERE status = ?';
$stmt = $conn->prepare($sql);
$result = $stmt->execute(['active']);

// Don't cache this query (default behavior)
$sql = 'SELECT * FROM users WHERE status = ?';
$stmt = $conn->prepare($sql);
$result = $stmt->execute(['active']);
```

### Automatic Invalidation (All Write Queries)

**All write queries trigger automatic invalidation** - no opt-in required:

```php
// INSERT - automatically triggers invalidation
$user = new User();
$user->setName('John');
$em->persist($user);
$em->flush();  // ← Server invalidates relevant cache entries

// UPDATE - automatically triggers invalidation
$user->setEmail('new@example.com');
$em->flush();  // ← Server invalidates cache entries for this user

// DELETE - automatically triggers invalidation
$em->remove($user);
$em->flush();  // ← Server invalidates cache entries for this user
```

The Cadabra server intelligently determines which cache entries to invalidate based on:
- Tables affected
- Rows modified
- Columns changed

### Example: Cache Hit Flow

```php
// First call - cache MISS
$user = $repository->createQueryBuilder('u')
    ->where('u.email = :email')
    ->setParameter('email', 'john@example.com')
    ->useCadabraCache()
    ->getQuery()
    ->getOneOrNullResult();

// Doctrine generates SQL: "SELECT t0.id, t0.name, t0.email FROM users t0 WHERE t0.email = ?"
// CadabraMiddleware sees /* CADABRA:USE */ comment
// → Sends RAW SQL to server
// → Server normalizes and generates fingerprint
// → Cache MISS
// → Executes real query, returns: [['id' => 10, 'name' => 'John', 'email' => 'john@example.com']]
// → Registers with server for caching and invalidation tracking
// → Returns CachedResult to Doctrine
// → Doctrine hydrates to User entity

// Second call - cache HIT
$user = $repository->createQueryBuilder('u')
    ->where('u.email = :email')
    ->setParameter('email', 'john@example.com')
    ->useCadabraCache()
    ->getQuery()
    ->getOneOrNullResult();

// → Sends RAW SQL to server
// → Server recognizes same fingerprint
// → Cache HIT - returns cached array directly
// → Doctrine hydrates from cached data
// → Result: User entity (no database query executed!)
```

## Features

### ✅ Lazy Loading Works

```php
$user = $repo->createQueryBuilder('u')
    ->where('u.id = :id')
    ->setParameter('id', 10)
    ->useCadabraCache()
    ->getQuery()
    ->getOneOrNullResult();  // Cached

$orders = $user->getOrders();  // Lazy load - NEW query, can also be cached if marked
```

### ✅ Transactions Work

```php
$em->beginTransaction();
try {
    $user->setEmail('new@example.com');
    $em->flush();  // Invalidation triggered
    $em->commit();
} catch (\Exception $e) {
    $em->rollback();  // No invalidation on rollback
}
```

### ✅ All Doctrine Features Work

- ✅ UnitOfWork change tracking
- ✅ Lifecycle events (PrePersist, PostLoad, etc.)
- ✅ Entity listeners
- ✅ Proxy objects for lazy loading
- ✅ Cascade operations
- ✅ Orphan removal
- ✅ Doctrine's second-level cache (independent layer)

## Configuration

### Available Settings

**`service_url`** (required)
URL of the Cadabra server. The server handles SQL normalization, cache key generation, and cache storage.

**`prefix`** (optional, default: `'cadabra'`)
Cache key prefix/namespace. Use different prefixes for different environments or applications sharing the same Cadabra server.

```yaml
# config/packages/cadabra.yaml
cadabra:
    service_url: 'http://localhost:6942'
    prefix: 'prod_myapp'  # Different prefix per environment
```

### Cache Storage

Cache is stored **on the Cadabra server**, not locally. This provides:
- **Shared cache** across multiple app servers
- **Centralized invalidation** - one server writes, all servers' cache updated
- **No local memory overhead** - cache lives on dedicated server
- **Persistent cache** - survives app restarts

## When to Use Caching

### ✅ Good Candidates for Caching

Mark these queries with `->useCadabraCache()`:

- **Read-heavy queries**: Product catalogs, user profiles, category lists
- **Expensive queries**: Complex JOINs, aggregations, GROUP BY
- **Frequently accessed data**: Homepage content, navigation menus
- **Paginated lists**: Search results, product listings
- **Static-ish data**: Settings, configurations, rarely updated content

### ❌ Don't Cache These

Leave these queries without `->useCadabraCache()`:

- **Financial transactions**: Require real-time accuracy
- **Queries with FOR UPDATE locks**: Transaction-sensitive
- **Audit logs**: Frequently changing, must be current
- **Real-time data**: Stock prices, live scores
- **One-time queries**: Reports, exports
- **Development/debugging**: When you need to see immediate changes

## Advanced Usage

### Alternative: Use Trait in Custom QueryBuilder

If you have an existing custom QueryBuilder and can't change repositories:

```php
namespace App\ORM;

use Cadabra\SymfonyBundle\ORM\CadabraQueryBuilderTrait;
use Doctrine\ORM\QueryBuilder;

class AppQueryBuilder extends QueryBuilder
{
    use CadabraQueryBuilderTrait;

    // Your existing methods here
}
```

Then configure your EntityManager to use it:

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        query_builder_class: App\ORM\AppQueryBuilder
```

### Manual Cache Control

```php
use Cadabra\Client\CadabraClient;

class CacheService
{
    public function __construct(private CadabraClient $client) {}

    public function clearTableCache(string $table): void
    {
        // Manually clear cache for a specific table
        $this->client->clearTable($table);
    }

    public function getStats(): array
    {
        return $this->client->getStats();
    }
}
```

### Monitoring & Debugging

Enable debug logging to see cache hits/misses:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        cadabra:
            type: stream
            path: '%kernel.logs_dir%/cadabra.log'
            level: debug
            channels: ['cadabra']
```

Log output:

```
[2024-01-15 10:23:45] cadabra.DEBUG: Cache HIT {"sql":"SELECT...","fingerprint":"abc123"}
[2024-01-15 10:23:46] cadabra.DEBUG: Cache MISS {"sql":"SELECT...","fingerprint":"def456"}
[2024-01-15 10:23:47] cadabra.DEBUG: Invalidation queued {"sql":"UPDATE users..."}
```

## Performance

### Typical Results

With opt-in caching on appropriate queries:

- **Cache hit rate**: 80-95% for marked queries
- **Response time improvement**: 2-5x faster for cached queries
- **Database load reduction**: 60-80% fewer queries on cached operations

### Overhead

- **Cache miss**: +2-5ms (server analysis + caching)
- **Cache hit**: +0.5-1ms (much faster than database)
- **Invalidation**: Async, zero overhead on writes

### Benchmark Results

From integration tests (50 iterations):

| Query Type | Cold (No Cache) | With Cache | Speedup |
|------------|-----------------|------------|---------|
| Simple lookup | 1.35ms | 634μs | 2.1x |
| JOIN with pagination | 648μs | 153μs | 4.2x |
| Price range filter | 6.01ms | 1.74ms | 3.5x |
| GROUP BY aggregate | 539μs | 144μs | 3.7x |
| Complex aggregate | 719μs | 151μs | 4.8x |

**Average speedup: 2.8x faster**

## Server Setup

### Using Docker (Recommended)

```bash
docker pull ghcr.io/sebastiaanwouters/cadabra:latest
docker run -d -p 6942:6942 --name cadabra-server \
  ghcr.io/sebastiaanwouters/cadabra:latest
```

### Verify Server is Running

```bash
curl http://localhost:6942/health
# Should return: {"status":"ok"}
```

### From Source

```bash
git clone https://github.com/SebastiaanWouters/cadabra
cd cadabra
# See repository README for setup instructions
```

## Development

### Running Tests

```bash
# Run all tests
composer test

# Run only unit tests
composer test:unit

# Run integration tests (requires Cadabra server)
cd symfony-test-app
vendor/bin/phpunit

# Check code style
composer cs:check

# Auto-fix code style
composer cs:fix

# Run all checks
composer check
```

### Testing in Your Application

**Option 1: Disable Cadabra in tests** (queries execute directly):

```yaml
# config/packages/test/cadabra.yaml
cadabra:
    service_url: 'http://localhost:6942'  # Point to test server
```

**Option 2: Mock the Cadabra client**:

```php
// In your test
$mockClient = $this->createMock(CadabraClient::class);
$mockClient->method('get')->willReturn(['id' => 1, 'name' => 'Test']);
```

**Option 3: Use in-memory test database** (fastest, most isolated):

```yaml
# config/packages/test/doctrine.yaml
doctrine:
    dbal:
        url: 'sqlite:///:memory:'
```

## Troubleshooting

### Queries Not Being Cached

**Check 1**: Did you add `->useCadabraCache()`?

```php
// ❌ NOT cached (missing ->useCadabraCache())
$users = $repo->createQueryBuilder('u')
    ->where('u.status = :status')
    ->getQuery()
    ->getResult();

// ✅ Cached
$users = $repo->createQueryBuilder('u')
    ->where('u.status = :status')
    ->useCadabraCache()  // ← Added
    ->getQuery()
    ->getResult();
```

**Check 2**: Is CadabraQueryBuilder being used?

```php
// Verify your repository extends CadabraRepository
class UserRepository extends CadabraRepository  // ← Must extend this
{
    // ...
}
```

**Check 3**: Is the server running?

```bash
curl http://localhost:6942/health
```

**Check 4**: Enable debug logging to see what's happening:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        main:
            level: debug
```

### Stale Data After Updates

If you see stale data after writes:

1. **Check write query executed**: Updates/deletes should trigger invalidation automatically
2. **Check server logs**: Look for invalidation messages
3. **Manually clear cache**:
   ```php
   $this->cadabraClient->clearTable('users');
   ```

### Performance Issues

If caching makes queries slower:

1. **Check network latency** to Cadabra server
2. **Verify server health**: `curl http://localhost:6942/health`
3. **Consider query complexity**: Very simple queries might be faster without caching
4. **Use caching selectively**: Only mark expensive queries with `->useCadabraCache()`

## Production Checklist

- [ ] Cadabra service running and healthy
- [ ] Prefix set to environment-specific value: `prod_myapp`
- [ ] Only expensive/frequently-accessed queries marked with `->useCadabraCache()`
- [ ] Monitoring and logging configured
- [ ] Cache stats monitored (hit rate, performance)
- [ ] Load testing performed with caching enabled

## How This Differs from Doctrine Cache

| Feature | Doctrine Result Cache | Cadabra |
|---------|----------------------|---------|
| **Caching Strategy** | Manual opt-in per query | Manual opt-in per query |
| **Interception Level** | Result set (after hydration) | DBAL (before hydration) |
| **Invalidation** | Manual/TTL only | Automatic on all writes |
| **Granularity** | Query-based | Row/column-aware |
| **Storage** | Local (per server) | Centralized server |
| **Normalization** | None | Server-side SQL normalization |
| **Multi-server** | Each server has own cache | Shared cache across servers |

## License

MIT

## Links

- [Cadabra Server](https://github.com/SebastiaanWouters/cadabra)
- [Package on Packagist](https://packagist.org/packages/cadabra/php)
- [Report Issues](https://github.com/SebastiaanWouters/cadabra-php/issues)
