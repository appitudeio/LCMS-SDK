[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

# LCMS-SDK

Add local settings:
https://docs.github.com/en/developers/overview/managing-deploy-keys#deploy-keys

Then composer.json:
```javascript
{
    "autoload": {
        "files": [
            "App/Utils.php"
        ],
        "psr-4": {
            "App\\" : "App"
        }
    },
    "require": {
    	"oakleaf/LCMS-SDK": "master@dev"
    },
    "repositories": [
	    {
	        "type": "vcs",
	        "url": "git@github.com:oakleaf/LCMS-SDK.git"
	    }
    ]
}
```

Lastly, init webpack: (https://www.valentinog.com/blog/webpack)
```
cd webpack
npm init -y
npm i webpack webpack-cli webpack-dev-server --save-dev
npm run dev
```
