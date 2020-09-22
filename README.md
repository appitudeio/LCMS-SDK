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


Lastly, start Webpack:
`cd webpack`
`npm start`
`nohup npm run dev &`
