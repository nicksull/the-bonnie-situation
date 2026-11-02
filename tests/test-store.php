<?php
/**
 * Bonnie_Store::delete_submissions().
 *
 * @package Bonnie
 */

class Test_Bonnie_Store extends Bonnie_TestCase {

	public function test_delete_removes_rows_and_meta_and_returns_count() {
		$ids  = $this->insert_submissions( $this->recent(), 3 );
		$keep = $this->insert_submissions( $this->recent(), 1 );

		$deleted = ( new Bonnie_Store() )->delete_submissions( $ids );

		$this->assertSame( 3, $deleted );
		$this->assertSame( 1, $this->count_rows( 'submissions' ) );
		$this->assertSame( 1, $this->count_rows( 'submission_meta' ) );
		$this->assertNotEmpty( $keep );
	}

	public function test_delete_fires_before_and_after_actions() {
		$ids = $this->insert_submissions( $this->recent(), 2 );

		( new Bonnie_Store() )->delete_submissions( $ids );

		$this->assertSame( 1, did_action( 'bonnie_before_delete_submissions' ) );
		$this->assertSame( 1, did_action( 'bonnie_after_delete_submissions' ) );
	}

	public function test_delete_throws_and_keeps_rows_when_meta_delete_fails() {
		$ids = $this->insert_submissions( $this->recent(), 2 );
		$this->fail_queries( '/^DELETE FROM `' . preg_quote( Bonnie_Store::table( 'submission_meta' ), '/' ) . '`/' );

		try {
			( new Bonnie_Store() )->delete_submissions( $ids );
			$this->fail( 'Expected an exception.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'submission meta', $e->getMessage() );
		}

		$this->assertSame( 2, $this->count_rows( 'submissions' ) );
		$this->assertSame( 2, $this->count_rows( 'submission_meta' ) );
		$this->assertSame( 0, did_action( 'bonnie_after_delete_submissions' ) );
	}

	public function test_delete_throws_and_rolls_back_when_row_delete_fails() {
		$ids = $this->insert_submissions( $this->recent(), 2 );
		$this->fail_queries( '/^DELETE FROM `' . preg_quote( Bonnie_Store::table( 'submissions' ), '/' ) . '`/' );

		$this->expectException( RuntimeException::class );
		try {
			( new Bonnie_Store() )->delete_submissions( $ids );
		} finally {
			// Meta was deleted inside the transaction, then rolled back.
			$this->assertSame( 2, $this->count_rows( 'submission_meta' ) );
			$this->assertSame( 2, $this->count_rows( 'submissions' ) );
		}
	}

	public function test_delete_with_no_ids_is_a_no_op() {
		$this->assertSame( 0, ( new Bonnie_Store() )->delete_submissions( array() ) );
		$this->assertSame( 0, did_action( 'bonnie_before_delete_submissions' ) );
	}
}
