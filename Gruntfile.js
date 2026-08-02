'use strict';

module.exports = function ( grunt ) {
	grunt.loadNpmTasks( 'grunt-banana-checker' );
	grunt.loadNpmTasks( 'grunt-eslint' );
	grunt.loadNpmTasks( 'grunt-stylelint' );

	grunt.initConfig( {
		eslint: {
			options: { cache: true },
			all: [ '**/*.{js,json}', '!node_modules/**', '!vendor/**', '!docs/**' ]
		},
		stylelint: {
			all: [ '**/*.css', '!node_modules/**', '!vendor/**', '!docs/**' ]
		},
		banana: {
			VaultTecMediaOptimizer: 'i18n/'
		}
	} );

	grunt.registerTask( 'test', [ 'eslint', 'stylelint', 'banana' ] );
	grunt.registerTask( 'default', 'test' );
};
