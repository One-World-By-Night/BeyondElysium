<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Moved every "am I already inside a transaction" decision into `Database\Transaction`'s call-depth counter.
 */
class TransactionPatternTest extends TestCase {

	public function test_only_the_transaction_class_manages_transactions(): void {
		$includes  = dirname( __DIR__, 2 ) . '/beyond-elysium/includes';
		$allowed   = realpath( $includes . '/Database/Transaction.php' );
		$offenders = [];

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $includes, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( $file->getExtension() !== 'php' || $file->getRealPath() === $allowed ) {
				continue;
			}
			foreach ( file( $file->getPathname() ) as $number => $line ) {
				if ( preg_match( '/@@autocommit|START TRANSACTION|SAVEPOINT|[\'"](COMMIT|ROLLBACK)[\'"]/', $line ) ) {
					$offenders[] = substr( $file->getPathname(), strlen( $includes ) + 1 ) . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame( [], $offenders, 'Use Database\Transaction::begin()/commit()/rollback() instead.' );
	}
}
