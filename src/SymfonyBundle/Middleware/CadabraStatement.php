<?php

declare(strict_types=1);

namespace Cadabra\SymfonyBundle\Middleware;

use Cadabra\Client\CadabraClient;
use Cadabra\SymfonyBundle\Service\CacheStrategy;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Statement wrapper that intercepts at DBAL level.
 * This is the KEY component - intercepts after SQL generation, before database execution.
 *
 * IMPORTANT: Sends RAW SQL to Cadabra server without normalization.
 * Server handles all normalization, cache key generation, and analysis.
 */
class CadabraStatement implements Statement
{
    private Statement $wrappedStatement;
    private string $sql;
    private CadabraClient $client;
    private CacheStrategy $strategy;
    private LoggerInterface $logger;
    private string $prefix;

    public function __construct(
        Statement $wrappedStatement,
        string $sql,
        CadabraClient $client,
        CacheStrategy $strategy,
        string $prefix,
        ?LoggerInterface $logger = null
    ) {
        $this->wrappedStatement = $wrappedStatement;
        $this->sql = $sql;
        $this->client = $client;
        $this->strategy = $strategy;
        $this->prefix = $prefix;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Execute the statement - THIS IS WHERE THE MAGIC HAPPENS.
     *
     * At this point:
     * - $this->sql is raw SQL from Doctrine: "SELECT t0.id FROM users t0 WHERE t0.id = ?"
     * - $params is: [10]
     * - We send RAW SQL to server (no normalization here)
     * - Server normalizes it and returns analysis
     *
     * {@inheritdoc}
     */
    public function execute($params = null): Result
    {
        // IMPORTANT: Keep $params as null if not provided - don't convert to []
        // If params were bound using bindValue/bindParam, execute(null) uses those
        // If we convert null to [], it overrides the bound params!

        // Check if this is a write query first
        if ($this->isWriteQuery()) {
            return $this->executeWriteWithInvalidation($params);
        }

        // For read queries: only use Cadabra if explicitly requested
        if (!$this->shouldUseCadabra()) {
            $this->logger->debug('Bypassing Cadabra (no CADABRA:USE comment)', ['sql' => $this->sql]);
            return $this->wrappedStatement->execute($params);
        }

        // Query has /* CADABRA:USE */ comment - use caching
        try {
            // Send RAW SQL to server - server does all normalization
            $analysis = $this->client->analyze($this->sql, $params ?? []);

            // Handle read with caching
            if (($analysis['operation_type'] ?? '') === 'read') {
                return $this->handleRead($params, $analysis);
            }

            // Fallback for any unexpected operation type
            return $this->wrappedStatement->execute($params);
        } catch (\Throwable $e) {
            // If anything fails with cache, fall back to normal execution
            $this->logger->warning('Cadabra execution failed, falling back to normal query', [
                'exception' => $e->getMessage(),
                'sql' => $this->sql,
            ]);

            return $this->wrappedStatement->execute($params);
        }
    }

    /**
     * Check if query should use Cadabra caching.
     * Only returns true if the query has the CADABRA:USE comment.
     */
    private function shouldUseCadabra(): bool
    {
        return stripos($this->sql, '/* CADABRA:USE */') !== false;
    }

    /**
     * Check if this is a write query (INSERT/UPDATE/DELETE).
     */
    private function isWriteQuery(): bool
    {
        return preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', trim($this->sql)) === 1;
    }

    /**
     * Execute write query and trigger invalidation.
     * Write queries ALWAYS trigger invalidation - no opt-out.
     */
    private function executeWriteWithInvalidation($params): Result
    {
        try {
            // Execute the write query first
            $result = $this->wrappedStatement->execute($params);

            // Trigger async invalidation (don't wait for it)
            // Server determines which cache keys to invalidate
            try {
                $this->client->invalidate($this->sql, $params ?? []);
                $this->logger->debug('Invalidation queued', ['sql' => $this->sql]);
            } catch (\Throwable $e) {
                // Log but don't fail the write operation
                $this->logger->error('Failed to queue invalidation', [
                    'exception' => $e->getMessage(),
                    'sql' => $this->sql,
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            // Rethrow - write failures should not be silently caught
            throw $e;
        }
    }

    /**
     * Handle SELECT query - check cache or execute and cache.
     *
     * @param array<mixed>|null $params
     * @param array<mixed> $analysis Analysis from server
     */
    private function handleRead($params, array $analysis): Result
    {
        // Apply client-side strategy: should we cache this query?
        if (!$this->strategy->shouldCache($analysis)) {
            $this->logger->debug('Query not cached (strategy)', ['sql' => $this->sql]);
            return $this->wrappedStatement->execute($params);
        }

        // Get fingerprint from server analysis
        $fingerprint = $analysis['cache_key']['fingerprint'] ?? null;
        if (!$fingerprint) {
            $this->logger->debug('No fingerprint from server', ['sql' => $this->sql]);
            return $this->wrappedStatement->execute($params);
        }

        try {
            // Try to get from server cache
            $cachedRows = $this->client->get($fingerprint);

            if ($cachedRows !== null) {
                // Cache HIT
                $this->logger->debug('Cache HIT', ['sql' => $this->sql, 'fingerprint' => $fingerprint]);
                return new CachedResult($cachedRows);
            }

            // Cache MISS - execute real query
            $this->logger->debug('Cache MISS', ['sql' => $this->sql, 'fingerprint' => $fingerprint]);

            $realResult = $this->wrappedStatement->execute($params);
            $rows = $realResult->fetchAllAssociative();

            // Register with Cadabra server for caching and invalidation tracking
            try {
                $this->client->register($this->sql, $params ?? [], $rows);
            } catch (\Throwable $e) {
                // Log but don't fail if registration fails
                $this->logger->warning('Failed to register cache with Cadabra', [
                    'exception' => $e->getMessage(),
                    'sql' => $this->sql,
                ]);
            }

            // Return CachedResult - Doctrine doesn't know the difference
            return new CachedResult($rows);
        } catch (\Throwable $e) {
            $this->logger->error('Cache operation failed', [
                'exception' => $e->getMessage(),
                'sql' => $this->sql,
            ]);

            // Fallback to normal execution
            return $this->wrappedStatement->execute($params);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function bindValue($param, $value, $type = ParameterType::STRING)
    {
        return $this->wrappedStatement->bindValue($param, $value, $type);
    }

    /**
     * {@inheritdoc}
     */
    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null)
    {
        return $this->wrappedStatement->bindParam($param, $variable, $type, $length);
    }
}
