<?php

declare(strict_types=1);

namespace Cadabra\SymfonyBundle\ORM;

use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Custom SQL Walker that injects the CADABRA:USE comment into generated SQL.
 *
 * This walker is automatically used when ->useCadabraCache() is called on QueryBuilder.
 */
class CadabraSqlWalker extends SqlWalker
{
    /**
     * Inject comment into SELECT statements.
     */
    public function walkSelectStatement(SelectStatement $AST): string
    {
        $sql = parent::walkSelectStatement($AST);

        if ($this->getQuery()->getHint('cadabra.use')) {
            $sql = '/* CADABRA:USE */ ' . $sql;
        }

        return $sql;
    }

    /**
     * Inject comment into UPDATE statements.
     */
    public function walkUpdateStatement(UpdateStatement $AST): string
    {
        $sql = parent::walkUpdateStatement($AST);

        if ($this->getQuery()->getHint('cadabra.use')) {
            $sql = '/* CADABRA:USE */ ' . $sql;
        }

        return $sql;
    }

    /**
     * Inject comment into DELETE statements.
     */
    public function walkDeleteStatement(DeleteStatement $AST): string
    {
        $sql = parent::walkDeleteStatement($AST);

        if ($this->getQuery()->getHint('cadabra.use')) {
            $sql = '/* CADABRA:USE */ ' . $sql;
        }

        return $sql;
    }
}
