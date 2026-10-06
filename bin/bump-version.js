#!/usr/bin/env node
/**
 * Bump the release version everywhere it lives.
 *
 * Usage: npm run bump -- 1.2.0
 *
 * Deliberately leaves BONNIE_DB_VERSION alone: that tracks the schema and
 * triggers the upgrade routine, so bump it by hand only with a schema change.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '..' );
const version = process.argv[ 2 ];

if ( ! /^\d+\.\d+\.\d+$/.test( version || '' ) ) {
	console.error( 'Usage: npm run bump -- <major.minor.patch>' );
	process.exit( 1 );
}

function replaceIn( file, pattern, label ) {
	const full = path.join( root, file );
	const src = fs.readFileSync( full, 'utf8' );
	if ( ! pattern.test( src ) ) {
		console.error( `✗ ${ label } not found in ${ file }` );
		process.exit( 1 );
	}
	fs.writeFileSync( full, src.replace( pattern, `$1${ version }$2` ) );
	console.log( `✓ ${ file }: ${ label }` );
}

replaceIn( 'the-bonnie-situation.php', /^(\s*\*\s*Version:\s*)[^\s]+()/m, 'Version header' );
replaceIn( 'the-bonnie-situation.php', /(define\(\s*'BONNIE_VERSION',\s*')[^']+('\s*\))/, 'BONNIE_VERSION' );
replaceIn( 'readme.txt', /^(Stable tag:\s*)[^\s]+()/m, 'Stable tag' );

for ( const file of [ 'package.json', 'package-lock.json' ] ) {
	const full = path.join( root, file );
	const json = JSON.parse( fs.readFileSync( full, 'utf8' ) );
	json.version = version;
	if ( json.packages && json.packages[ '' ] ) {
		json.packages[ '' ].version = version;
	}
	fs.writeFileSync( full, JSON.stringify( json, null, 2 ) + '\n' );
	console.log( `✓ ${ file }: version` );
}

const readme = fs.readFileSync( path.join( root, 'readme.txt' ), 'utf8' );
const escaped = version.replace( /\./g, '\\.' );
const changelog = ( readme.split( /^== Changelog ==$/m )[ 1 ] || '' ).split( /^== /m )[ 0 ];
if ( ! new RegExp( `^= ${ escaped } =`, 'm' ).test( changelog ) ) {
	console.warn( `⚠ readme.txt has no "= ${ version } =" changelog entry — add one before releasing.` );
}
