<?php
	/**
	 *	Changelog:
	 * 	- 2023-11-10: Added currencies
	 * 	- 2023-11-10: Refactor the whole structure to be based on the Enum(s)
	 */
	namespace LCMS\Core;

	use LCMS\Core\Request;
	use LCMS\Util\Singleton;

	use \Exception;

	define("COUNTRY_CODE", 'code');
	define("COUNTRY_PHONE", 'phone');
	define("COUNTRY_NAME", 'name');	
	define("LANGUAGE_CODE", 'language');
	define("CURRENCY", 'currency');
	define("LOCALE", "locale");

	class Locale
	{
		use Singleton;

		private array $languages = [];
		private array $config = [
			COUNTRY_CODE => null,
			COUNTRY_PHONE => null,
			COUNTRY_NAME => null,
			LANGUAGE_CODE => null,
			CURRENCY => null,
			LOCALE => null
		];
		private bool $is_default = false;

		public function setLanguages(array $_languages): self
		{
			$this->languages = $_languages;

			if(false === $this->isDefault() && in_array($this->config[LANGUAGE_CODE], $this->languages))
			{
				$this->is_default = true;
			}

			return $this;
		}
		
		public function setLanguage(string $_language, bool $_is_default = false): void
		{
			$this->config[LANGUAGE_CODE] = $_language;
			$this->is_default = $_is_default;
		}

		public function setCountry(string $_country_code): void
		{
			if(!$ct = CountryType::find($_country_code))
			{
				throw new Exception("Cant set country from country_code: " . $_country_code);
			}

			$this->config = [
				COUNTRY_CODE => $ct->getCode(),
				COUNTRY_PHONE => $ct->getNumber(),
				COUNTRY_NAME => $ct->getName(),
				LANGUAGE_CODE => $ct->getLanguage(),
				CURRENCY => $ct->getCurrency(),
				LOCALE => $ct->getLocale()
			];
		}

		public function setCurrency(string $_currency): void
		{
			$this->config[CURRENCY] = $_currency;
		}

		public static function getLanguage(): string
		{
			return self::getInstance()->asArray()[LANGUAGE_CODE] ?? false;
		}

		public static function getLocale(): string | false
		{
			return self::getInstance()->asArray()[LOCALE] ?? false;
		}

		/**
		 * 	Not sure why this looks like this
		 */
		public static function getCountry(string $_country_code = null): array | bool
		{
			return self::getInstance()->asArray()[COUNTRY_NAME] ?? false;
		}

		public function getCurrency(string $_country_code): string | false
		{
			return self::getInstance()->asArray()[CURRENCY] ?? false;
		}

		public function asArray()
		{
			return array_filter($this->config);
		}

		public static function isDefault(): bool
		{
			return self::getInstance()->is_default;
		}

		/**
		 *	Parse Locale from URL
		 */
		public function extract(Request $request): string | bool
		{
			if(!$test_language = (count($request->segments()) > 0 && strlen($request->segments()[0]) == 2) ? strtolower($request->segments()[0]) : false)
			{
				return false;
			}
			elseif(!in_array($test_language, $this->languages))
			{
				return false;
			}
			elseif($test_language != $this->config[LANGUAGE_CODE] && $this->isDefault() === true)
			{
				$this->is_default = false;
			}

			$this->config[LANGUAGE_CODE] = $test_language;

			return $this->config[LANGUAGE_CODE];
		}		
	}
	
	enum CountryType
	{
		case BANGLADESH;
		case BELGIUM;
		case BURKINA_FASO;
		case BULGARIA;
		case BOSNIA_AND_HERZEGOVINA;
		case BARBADOS;
		case WALLIS_AND_FUTUNA;
		case SAINT_BARTHELEMY;
		case BERMUDA;
		case BRUNEI;
		case BOLIVIA;
		case BAHRAIN;
		case BURUNDI;
		case BENIN;
		case BHUTAN;
		case JAMAICA;
		case BOUVET_ISLAND;
		case BOTSWANA;
		case SAMOA;
		case BONAIRE_SAINT_EUSTATIUS_AND_SABA;
		case BRAZIL;
		case BAHAMAS;
		case JERSEY;
		case BELARUS;
		case BELIZE;
		case RUSSIA;
		case RWANDA;
		case SERBIA;
		case EAST_TIMOR;
		case REUNION;
		case TURKMENISTAN;
		case TAJIKISTAN;
		case ROMANIA;
		case TOKELAU;
		case GUINEA_BISSAU;
		case GUAM;
		case GUATEMALA;
		case SOUTH_GEORGIA_AND_THE_SOUTH_SANDWICH_ISLANDS;
		case GREECE;
		case EQUATORIAL_GUINEA;
		case GUADELOUPE;
		case JAPAN;
		case GUYANA;
		case GUERNSEY;
		case FRENCH_GUIANA;
		case GEORGIA;
		case GRENADA;
		case UNITED_KINGDOM;
		case GABON;
		case EL_SALVADOR;
		case GUINEA;
		case GAMBIA;
		case GREENLAND;
		case GIBRALTAR;
		case GHANA;
		case OMAN;
		case TUNISIA;
		case JORDAN;
		case CROATIA;
		case HAITI;
		case HUNGARY;
		case HONG_KONG;
		case HONDURAS;
		case HEARD_ISLAND_AND_MCDONALD_ISLANDS;
		case VENEZUELA;
		case PUERTO_RICO;
		case PALESTINIAN_TERRITORY;
		case PALAU;
		case PORTUGAL;
		case SVALBARD_AND_JAN_MAYEN;
		case PARAGUAY;
		case IRAQ;
		case PANAMA;
		case FRENCH_POLYNESIA;
		case PAPUA_NEW_GUINEA;
		case PERU;
		case PAKISTAN;
		case PHILIPPINES;
		case PITCAIRN;
		case POLAND;
		case SAINT_PIERRE_AND_MIQUELON;
		case ZAMBIA;
		case WESTERN_SAHARA;
		case ESTONIA;
		case EGYPT;
		case SOUTH_AFRICA;
		case ECUADOR;
		case ITALY;
		case VIETNAM;
		case SOLOMON_ISLANDS;
		case ETHIOPIA;
		case SOMALIA;
		case ZIMBABWE;
		case SAUDI_ARABIA;
		case SPAIN;
		case ERITREA;
		case MONTENEGRO;
		case MOLDOVA;
		case MADAGASCAR;
		case SAINT_MARTIN;
		case MOROCCO;
		case MONACO;
		case UZBEKISTAN;
		case MYANMAR;
		case MALI;
		case MACAO;
		case MONGOLIA;
		case MARSHALL_ISLANDS;
		case MACEDONIA;
		case MAURITIUS;
		case MALTA;
		case MALAWI;
		case MALDIVES;
		case MARTINIQUE;
		case NORTHERN_MARIANA_ISLANDS;
		case MONTSERRAT;
		case MAURITANIA;
		case ISLE_OF_MAN;
		case UGANDA;
		case TANZANIA;
		case MALAYSIA;
		case MEXICO;
		case ISRAEL;
		case FRANCE;
		case BRITISH_INDIAN_OCEAN_TERRITORY;
		case SAINT_HELENA;
		case FINLAND;
		case FIJI;
		case FALKLAND_ISLANDS;
		case MICRONESIA;
		case FAROE_ISLANDS;
		case NICARAGUA;
		case NETHERLANDS;
		case NORWAY;
		case NAMIBIA;
		case VANUATU;
		case NEW_CALEDONIA;
		case NIGER;
		case NORFOLK_ISLAND;
		case NIGERIA;
		case NEW_ZEALAND;
		case NEPAL;
		case NAURU;
		case NIUE;
		case COOK_ISLANDS;
		case KOSOVO;
		case IVORY_COAST;
		case SWITZERLAND;
		case COLOMBIA;
		case CHINA;
		case CAMEROON;
		case CHILE;
		case COCOS_ISLANDS;
		case CANADA;
		case REPUBLIC_OF_THE_CONGO;
		case CENTRAL_AFRICAN_REPUBLIC;
		case DEMOCRATIC_REPUBLIC_OF_THE_CONGO;
		case CZECH_REPUBLIC;
		case CYPRUS;
		case CHRISTMAS_ISLAND;
		case COSTA_RICA;
		case CURACAO;
		case CAPE_VERDE;
		case CUBA;
		case SWAZILAND;
		case SYRIA;
		case SINT_MAARTEN;
		case KYRGYZSTAN;
		case KENYA;
		case SOUTH_SUDAN;
		case SURINAME;
		case KIRIBATI;
		case CAMBODIA;
		case SAINT_KITTS_AND_NEVIS;
		case COMOROS;
		case SAO_TOME_AND_PRINCIPE;
		case SLOVAKIA;
		case SOUTH_KOREA;
		case SLOVENIA;
		case NORTH_KOREA;
		case KUWAIT;
		case SENEGAL;
		case SAN_MARINO;
		case SIERRA_LEONE;
		case SEYCHELLES;
		case KAZAKHSTAN;
		case CAYMAN_ISLANDS;
		case SINGAPORE;
		case SWEDEN;
		case SUDAN;
		case DOMINICAN_REPUBLIC;
		case DOMINICA;
		case DJIBOUTI;
		case DENMARK;
		case BRITISH_VIRGIN_ISLANDS;
		case GERMANY;
		case YEMEN;
		case ALGERIA;
		case UNITED_STATES;
		case URUGUAY;
		case MAYOTTE;
		case UNITED_STATES_MINOR_OUTLYING_ISLANDS;
		case LEBANON;
		case SAINT_LUCIA;
		case LAOS;
		case TUVALU;
		case TAIWAN;
		case TRINIDAD_AND_TOBAGO;
		case TURKEY;
		case SRI_LANKA;
		case LIECHTENSTEIN;
		case LATVIA;
		case TONGA;
		case LITHUANIA;
		case LUXEMBOURG;
		case LIBERIA;
		case LESOTHO;
		case THAILAND;
		case FRENCH_SOUTHERN_TERRITORIES;
		case TOGO;
		case CHAD;
		case TURKS_AND_CAICOS_ISLANDS;
		case LIBYA;
		case VATICAN;
		case SAINT_VINCENT_AND_THE_GRENADINES;
		case UNITED_ARAB_EMIRATES;
		case ANDORRA;
		case ANTIGUA_AND_BARBUDA;
		case AFGHANISTAN;
		case ANGUILLA;
		case US_VIRGIN_ISLANDS;
		case ICELAND;
		case IRAN;
		case ARMENIA;
		case ALBANIA;
		case ANGOLA;
		case ANTARCTICA;
		case AMERICAN_SAMOA;
		case ARGENTINA;
		case AUSTRALIA;
		case AUSTRIA;
		case ARUBA;
		case INDIA;
		case ALAND_ISLANDS;
		case AZERBAIJAN;
		case IRELAND;
		case INDONESIA;
		case UKRAINE;
		case QATAR;
		case MOZAMBIQUE;
	
		public function getCode(): string
		{
			return $this->getData(COUNTRY_CODE);
		}
	
		public function getNumber(): string
		{
			return $this->getData(COUNTRY_PHONE);
		}
	
		public function getName(): string
		{
			return $this->getData(COUNTRY_NAME);
		}

		public function getLanguage(): string
		{
			return $this->getData(LANGUAGE_CODE) ?? "en";
		}

		public function getLocale(string $_divider = "_"): string
		{
			return $this->getLanguage() . $_divider . $this->getCode();
		}

		public function getCurrency(): string
		{
			return $this->getData(CURRENCY);
		}
	
		public function getData(string | null $_type = null): string 
		{
			$country = match($this) 
			{
				self::BANGLADESH => [COUNTRY_NAME => 'Bangladesh', COUNTRY_CODE => 'BD', COUNTRY_PHONE => '880', LANGUAGE_CODE => 'bn', CURRENCY => 'BDT'],
				self::BELGIUM => [COUNTRY_NAME => 'Belgium', COUNTRY_CODE => 'BE', COUNTRY_PHONE => '32', LANGUAGE_CODE => 'nl', CURRENCY => 'EUR'],
				self::BURKINA_FASO => [COUNTRY_NAME => 'Burkina Faso', COUNTRY_CODE => 'BF', COUNTRY_PHONE => '226', LANGUAGE_CODE => 'fr', CURRENCY => 'XOF'],
				self::BULGARIA => [COUNTRY_NAME => 'Bulgaria', COUNTRY_CODE => 'BG', COUNTRY_PHONE => '359', LANGUAGE_CODE => 'bg', CURRENCY => 'BGN'],
				self::BOSNIA_AND_HERZEGOVINA => [COUNTRY_NAME => 'Bosnia and Herzegovina', COUNTRY_CODE => 'BA', COUNTRY_PHONE => '387', LANGUAGE_CODE => 'bs', CURRENCY => 'BAM'],
				self::BARBADOS => [COUNTRY_NAME => 'Barbados', COUNTRY_CODE => 'BB', COUNTRY_PHONE => '1-246', LANGUAGE_CODE => 'en', CURRENCY => 'BBD'],
				self::WALLIS_AND_FUTUNA => [COUNTRY_NAME => 'Wallis and Futuna', COUNTRY_CODE => 'WF', COUNTRY_PHONE => '681', LANGUAGE_CODE => 'fr', CURRENCY => 'XPF'],
				self::SAINT_BARTHELEMY => [COUNTRY_NAME => 'Saint Barthelemy', COUNTRY_CODE => 'BL', COUNTRY_PHONE => '590', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR'],
				self::BERMUDA => [COUNTRY_NAME => 'Bermuda', COUNTRY_CODE => 'BM', COUNTRY_PHONE => '1-441', LANGUAGE_CODE => 'en', CURRENCY => 'BMD'],
				self::BRUNEI => [COUNTRY_NAME => 'Brunei', COUNTRY_CODE => 'BN', COUNTRY_PHONE => '673', LANGUAGE_CODE => 'ms', CURRENCY => 'BND'],
				self::BOLIVIA => [COUNTRY_NAME => 'Bolivia', COUNTRY_CODE => 'BO', COUNTRY_PHONE => '591', LANGUAGE_CODE => 'es', CURRENCY => 'BOB'],
				self::BAHRAIN => [COUNTRY_NAME => 'Bahrain', COUNTRY_CODE => 'BH', COUNTRY_PHONE => '973', LANGUAGE_CODE => 'ar', CURRENCY => 'BHD'],
				self::BURUNDI => [COUNTRY_NAME => 'Burundi', COUNTRY_CODE => 'BI', COUNTRY_PHONE => '257', LANGUAGE_CODE => 'fr', CURRENCY => 'BIF'],
				self::BENIN => [COUNTRY_NAME => 'Benin', COUNTRY_CODE => 'BJ', COUNTRY_PHONE => '229', LANGUAGE_CODE => 'fr', CURRENCY => 'XOF'],
				self::BHUTAN => [COUNTRY_NAME => 'Bhutan', COUNTRY_CODE => 'BT', COUNTRY_PHONE => '975', LANGUAGE_CODE => 'dz', CURRENCY => 'BTN'],
				self::JAMAICA => [COUNTRY_NAME => 'Jamaica', COUNTRY_CODE => 'JM', COUNTRY_PHONE => '1-876', LANGUAGE_CODE => 'en', CURRENCY => 'JMD'],
				self::BOUVET_ISLAND => [COUNTRY_NAME => 'Bouvet Island', COUNTRY_CODE => 'BV', COUNTRY_PHONE => '', LANGUAGE_CODE => 'en', CURRENCY => 'NOK'],
				self::BOTSWANA => [COUNTRY_NAME => 'Botswana', COUNTRY_CODE => 'BW', COUNTRY_PHONE => '267', LANGUAGE_CODE => 'en', CURRENCY => 'BWP'],
				self::SAMOA => [COUNTRY_NAME => 'Samoa', COUNTRY_CODE => 'WS', COUNTRY_PHONE => '685', LANGUAGE_CODE => 'en', CURRENCY => 'WST'],
				self::BONAIRE_SAINT_EUSTATIUS_AND_SABA => [COUNTRY_NAME => 'Bonaire, Saint Eustatius and Saba ', COUNTRY_CODE => 'BQ', COUNTRY_PHONE => '599', LANGUAGE_CODE => 'nl', CURRENCY => 'USD'],
				self::BRAZIL => [COUNTRY_NAME => 'Brazil', COUNTRY_CODE => 'BR', COUNTRY_PHONE => '55', LANGUAGE_CODE => 'pt', CURRENCY => 'BRL'],
				self::BAHAMAS => [COUNTRY_NAME => 'Bahamas', COUNTRY_CODE => 'BS', COUNTRY_PHONE => '1-242', LANGUAGE_CODE => 'en', CURRENCY => 'BSD'],
				self::JERSEY => [COUNTRY_NAME => 'Jersey', COUNTRY_CODE => 'JE', COUNTRY_PHONE => '44-1534', LANGUAGE_CODE => 'en', CURRENCY => 'GBP'],
				self::BELARUS => [COUNTRY_NAME => 'Belarus', COUNTRY_CODE => 'BY', COUNTRY_PHONE => '375', LANGUAGE_CODE => 'be', CURRENCY => 'BYN'],
				self::BELIZE => [COUNTRY_NAME => 'Belize', COUNTRY_CODE => 'BZ', COUNTRY_PHONE => '501', LANGUAGE_CODE => 'en', CURRENCY => 'BZD'],
				self::RUSSIA => [COUNTRY_NAME => 'Russia', COUNTRY_CODE => 'RU', COUNTRY_PHONE => '7', LANGUAGE_CODE => 'ru', CURRENCY => 'RUB'],
				self::RWANDA => [COUNTRY_NAME => 'Rwanda', COUNTRY_CODE => 'RW', COUNTRY_PHONE => '250', LANGUAGE_CODE => 'fr', CURRENCY => 'RWF'],
				self::SERBIA => [COUNTRY_NAME => 'Serbia', COUNTRY_CODE => 'RS', COUNTRY_PHONE => '381', LANGUAGE_CODE => 'sr', CURRENCY => 'RSD'],
				self::EAST_TIMOR => [COUNTRY_NAME => 'East Timor', COUNTRY_CODE => 'TL', COUNTRY_PHONE => '670', LANGUAGE_CODE => 'tet', CURRENCY => 'USD'],
				self::REUNION => [COUNTRY_NAME => 'Reunion', COUNTRY_CODE => 'RE', COUNTRY_PHONE => '262', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR'], 
				self::TURKMENISTAN => [COUNTRY_NAME => 'Turkmenistan', COUNTRY_CODE => 'TM', COUNTRY_PHONE => '993', LANGUAGE_CODE => 'tk', CURRENCY => 'TMT'],
				self::TAJIKISTAN => [COUNTRY_NAME => 'Tajikistan', COUNTRY_CODE => 'TJ', COUNTRY_PHONE => '992', LANGUAGE_CODE => 'tg', CURRENCY => 'TJS'],
				self::ROMANIA => [COUNTRY_NAME => 'Romania', COUNTRY_CODE => 'RO', COUNTRY_PHONE => '40', LANGUAGE_CODE => 'ro', CURRENCY => 'RON'],
				self::TOKELAU => [COUNTRY_NAME => 'Tokelau', COUNTRY_CODE => 'TK', COUNTRY_PHONE => '690', LANGUAGE_CODE => 'tk', CURRENCY => 'NZD'],
				self::GUINEA_BISSAU => [COUNTRY_NAME => 'Guinea-Bissau', COUNTRY_CODE => 'GW', COUNTRY_PHONE => '245', LANGUAGE_CODE => 'pt', CURRENCY => 'XOF'],
				self::GUAM => [COUNTRY_NAME => 'Guam', COUNTRY_CODE => 'GU', COUNTRY_PHONE => '1-671', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::GUATEMALA => [COUNTRY_NAME => 'Guatemala', COUNTRY_CODE => 'GT', COUNTRY_PHONE => '502', LANGUAGE_CODE => 'es', CURRENCY => 'GTQ'],
				self::SOUTH_GEORGIA_AND_THE_SOUTH_SANDWICH_ISLANDS => [COUNTRY_NAME => 'South Georgia and the South Sandwich Islands', COUNTRY_CODE => 'GS', COUNTRY_PHONE => '', LANGUAGE_CODE => 'en', CURRENCY => 'GBP'],
				self::GREECE => [COUNTRY_NAME => 'Greece', COUNTRY_CODE => 'GR', COUNTRY_PHONE => '30', LANGUAGE_CODE => 'el', CURRENCY => 'USD'],
				self::EQUATORIAL_GUINEA => [COUNTRY_NAME => 'Equatorial Guinea', COUNTRY_CODE => 'GQ', COUNTRY_PHONE => '240', LANGUAGE_CODE => 'es', CURRENCY => 'EUR'],
				self::GUADELOUPE => [COUNTRY_NAME => 'Guadeloupe', COUNTRY_CODE => 'GP', COUNTRY_PHONE => '590', LANGUAGE_CODE => 'fr', CURRENCY => 'USD'],
				self::JAPAN => [COUNTRY_NAME => 'Japan', COUNTRY_CODE => 'JP', COUNTRY_PHONE => '81', LANGUAGE_CODE => 'ja', CURRENCY => 'JPY'],
				self::GUYANA => [COUNTRY_NAME => 'Guyana', COUNTRY_CODE => 'GY', COUNTRY_PHONE => '592', LANGUAGE_CODE => 'en', CURRENCY => 'GYD'],
				self::GUERNSEY => [COUNTRY_NAME => 'Guernsey', COUNTRY_CODE => 'GG', COUNTRY_PHONE => '44-1481', LANGUAGE_CODE => 'en', CURRENCY => 'GBP'],
				self::FRENCH_GUIANA => [COUNTRY_NAME => 'French Guiana', COUNTRY_CODE => 'GF', COUNTRY_PHONE => '594', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR'],
				self::GEORGIA => [COUNTRY_NAME => 'Georgia', COUNTRY_CODE => 'GE', COUNTRY_PHONE => '995', LANGUAGE_CODE => 'ka', CURRENCY => 'GEL'],
				self::GRENADA => [COUNTRY_NAME => 'Grenada', COUNTRY_CODE => 'GD', COUNTRY_PHONE => '1-473', LANGUAGE_CODE => 'en', CURRENCY => 'XCD'],
				self::UNITED_KINGDOM => [COUNTRY_NAME => 'United Kingdom', COUNTRY_CODE => 'GB', COUNTRY_PHONE => '44', LANGUAGE_CODE => 'en', CURRENCY => 'GBP'],
				self::GABON => [COUNTRY_NAME => 'Gabon', COUNTRY_CODE => 'GA', COUNTRY_PHONE => '241', LANGUAGE_CODE => 'fr', CURRENCY => 'XAF'],
				self::EL_SALVADOR => [COUNTRY_NAME => 'El Salvador', COUNTRY_CODE => 'SV', COUNTRY_PHONE => '503', LANGUAGE_CODE => 'es', CURRENCY => 'USD'],
				self::GUINEA => [COUNTRY_NAME => 'Guinea', COUNTRY_CODE => 'GN', COUNTRY_PHONE => '224', LANGUAGE_CODE => 'fr', CURRENCY => 'GNF'],
				self::GAMBIA => [COUNTRY_NAME => 'Gambia', COUNTRY_CODE => 'GM', COUNTRY_PHONE => '220', LANGUAGE_CODE => 'en', CURRENCY => 'GMD'],
				self::GREENLAND => [COUNTRY_NAME => 'Greenland', COUNTRY_CODE => 'GL', COUNTRY_PHONE => '299', LANGUAGE_CODE => 'kl', CURRENCY => 'DKK'],
				self::GIBRALTAR => [COUNTRY_NAME => 'Gibraltar', COUNTRY_CODE => 'GI', COUNTRY_PHONE => '350', LANGUAGE_CODE => 'en', CURRENCY => 'GIP'],
				self::GHANA => [COUNTRY_NAME => 'Ghana', COUNTRY_CODE => 'GH', COUNTRY_PHONE => '233', LANGUAGE_CODE => 'en', CURRENCY => 'GHS'],
				self::OMAN => [COUNTRY_NAME => 'Oman', COUNTRY_CODE => 'OM', COUNTRY_PHONE => '968', LANGUAGE_CODE => 'ar', CURRENCY => 'OMR'],
				self::TUNISIA => [COUNTRY_NAME => 'Tunisia', COUNTRY_CODE => 'TN', COUNTRY_PHONE => '216', LANGUAGE_CODE => 'ar', CURRENCY => 'TND'],
				self::JORDAN => [COUNTRY_NAME => 'Jordan', COUNTRY_CODE => 'JO', COUNTRY_PHONE => '962', LANGUAGE_CODE => 'ar', CURRENCY => 'JOD'],
				self::CROATIA => [COUNTRY_NAME => 'Croatia', COUNTRY_CODE => 'HR', COUNTRY_PHONE => '385', LANGUAGE_CODE => 'hr', CURRENCY => 'HRK'],
				self::HAITI => [COUNTRY_NAME => 'Haiti', COUNTRY_CODE => 'HT', COUNTRY_PHONE => '509', LANGUAGE_CODE => 'fr', CURRENCY => 'HTG'],
				self::HUNGARY => [COUNTRY_NAME => 'Hungary', COUNTRY_CODE => 'HU', COUNTRY_PHONE => '36', LANGUAGE_CODE => 'hu', CURRENCY => 'HUF'],
				self::HONG_KONG => [COUNTRY_NAME => 'Hong Kong', COUNTRY_CODE => 'HK', COUNTRY_PHONE => '852', LANGUAGE_CODE => 'en', CURRENCY => 'HKD'],
				self::HONDURAS => [COUNTRY_NAME => 'Honduras', COUNTRY_CODE => 'HN', COUNTRY_PHONE => '504', LANGUAGE_CODE => 'es', CURRENCY => 'HNL'],
				self::HEARD_ISLAND_AND_MCDONALD_ISLANDS => [COUNTRY_NAME => 'Heard Island and McDonald Islands', COUNTRY_CODE => 'HM', COUNTRY_PHONE => ' ', LANGUAGE_CODE => 'en', CURRENCY => 'AUD'],
				self::VENEZUELA => [COUNTRY_NAME => 'Venezuela', COUNTRY_CODE => 'VE', COUNTRY_PHONE => '58', LANGUAGE_CODE => 'es', CURRENCY => 'VES'],
				self::PUERTO_RICO => [COUNTRY_NAME => 'Puerto Rico', COUNTRY_CODE => 'PR', COUNTRY_PHONE => '1-787 and 1-939', LANGUAGE_CODE => 'es', CURRENCY => 'USD'],
				self::PALESTINIAN_TERRITORY => [COUNTRY_NAME => 'Palestinian Territory', COUNTRY_CODE => 'PS', COUNTRY_PHONE => '970', LANGUAGE_CODE => 'ar', CURRENCY => 'ILS'],
				self::PALAU => [COUNTRY_NAME => 'Palau', COUNTRY_CODE => 'PW', COUNTRY_PHONE => '680', LANGUAGE_CODE => 'pa', CURRENCY => 'USD'],
				self::PORTUGAL => [COUNTRY_NAME => 'Portugal', COUNTRY_CODE => 'PT', COUNTRY_PHONE => '351', LANGUAGE_CODE => 'ps', CURRENCY => 'EUR'],
				self::SVALBARD_AND_JAN_MAYEN => [COUNTRY_NAME => 'Svalbard and Jan Mayen', COUNTRY_CODE => 'SJ', COUNTRY_PHONE => '47', LANGUAGE_CODE => 'no', CURRENCY => 'NOK'],
				self::PARAGUAY => [COUNTRY_NAME => 'Paraguay', COUNTRY_CODE => 'PY', COUNTRY_PHONE => '595', LANGUAGE_CODE => 'es', CURRENCY => 'PYG'],
				self::IRAQ => [COUNTRY_NAME => 'Iraq', COUNTRY_CODE => 'IQ', COUNTRY_PHONE => '964', LANGUAGE_CODE => 'ar', CURRENCY => 'IQD'],
				self::PANAMA => [COUNTRY_NAME => 'Panama', COUNTRY_CODE => 'PA', COUNTRY_PHONE => '507', LANGUAGE_CODE => 'es', CURRENCY => 'PAB'],
				self::FRENCH_POLYNESIA => [COUNTRY_NAME => 'French Polynesia', COUNTRY_CODE => 'PF', COUNTRY_PHONE => '689', LANGUAGE_CODE => 'fr', CURRENCY => 'XPF'],
				self::PAPUA_NEW_GUINEA => [COUNTRY_NAME => 'Papua New Guinea', COUNTRY_CODE => 'PG', COUNTRY_PHONE => '675', LANGUAGE_CODE => 'en', CURRENCY => 'PGK'],
				self::PERU => [COUNTRY_NAME => 'Peru', COUNTRY_CODE => 'PE', COUNTRY_PHONE => '51', LANGUAGE_CODE => 'es', CURRENCY => 'USD'],
				self::PAKISTAN => [COUNTRY_NAME => 'Pakistan', COUNTRY_CODE => 'PK', COUNTRY_PHONE => '92', LANGUAGE_CODE => 'ur', CURRENCY => 'USD'],
				self::PHILIPPINES => [COUNTRY_NAME => 'Philippines', COUNTRY_CODE => 'PH', COUNTRY_PHONE => '63', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::PITCAIRN => [COUNTRY_NAME => 'Pitcairn', COUNTRY_CODE => 'PN', COUNTRY_PHONE => '870', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::POLAND => [COUNTRY_NAME => 'Poland', COUNTRY_CODE => 'PL', COUNTRY_PHONE => '48', LANGUAGE_CODE => 'pl', CURRENCY => 'USD'],
				self::SAINT_PIERRE_AND_MIQUELON => [COUNTRY_NAME => 'Saint Pierre and Miquelon', COUNTRY_CODE => 'PM', COUNTRY_PHONE => '508', LANGUAGE_CODE => 'fr', CURRENCY => 'USD'],
				self::ZAMBIA => [COUNTRY_NAME => 'Zambia', COUNTRY_CODE => 'ZM', COUNTRY_PHONE => '260', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::WESTERN_SAHARA => [COUNTRY_NAME => 'Western Sahara', COUNTRY_CODE => 'EH', COUNTRY_PHONE => '212', LANGUAGE_CODE => 'ar', CURRENCY => 'USD'],
				self::ESTONIA => [COUNTRY_NAME => 'Estonia', COUNTRY_CODE => 'EE', COUNTRY_PHONE => '372', LANGUAGE_CODE => 'et', CURRENCY => 'USD'],
				self::EGYPT => [COUNTRY_NAME => 'Egypt', COUNTRY_CODE => 'EG', COUNTRY_PHONE => '20', LANGUAGE_CODE => 'ar', CURRENCY => 'USD'],
				self::SOUTH_AFRICA => [COUNTRY_NAME => 'South Africa', COUNTRY_CODE => 'ZA', COUNTRY_PHONE => '27', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::ECUADOR => [COUNTRY_NAME => 'Ecuador', COUNTRY_CODE => 'EC', COUNTRY_PHONE => '593', LANGUAGE_CODE => 'es', CURRENCY => 'USD'],
				self::ITALY => [COUNTRY_NAME => 'Italy', COUNTRY_CODE => 'IT', COUNTRY_PHONE => '39', LANGUAGE_CODE => 'it', CURRENCY => 'USD'],
				self::VIETNAM => [COUNTRY_NAME => 'Vietnam', COUNTRY_CODE => 'VN', COUNTRY_PHONE => '84', LANGUAGE_CODE => 'vi', CURRENCY => 'USD'],
				self::SOLOMON_ISLANDS => [COUNTRY_NAME => 'Solomon Islands', COUNTRY_CODE => 'SB', COUNTRY_PHONE => '677', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::ETHIOPIA => [COUNTRY_NAME => 'Ethiopia', COUNTRY_CODE => 'ET', COUNTRY_PHONE => '251', LANGUAGE_CODE => 'am', CURRENCY => 'USD'],
				self::SOMALIA => [COUNTRY_NAME => 'Somalia', COUNTRY_CODE => 'SO', COUNTRY_PHONE => '252', LANGUAGE_CODE => 'so', CURRENCY => 'USD'],
				self::ZIMBABWE => [COUNTRY_NAME => 'Zimbabwe', COUNTRY_CODE => 'ZW', COUNTRY_PHONE => '263', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::SAUDI_ARABIA => [COUNTRY_NAME => 'Saudi Arabia', COUNTRY_CODE => 'SA', COUNTRY_PHONE => '966', LANGUAGE_CODE => 'ar', CURRENCY => 'USD'],
				self::SPAIN => [COUNTRY_NAME => 'Spain', COUNTRY_CODE => 'ES', COUNTRY_PHONE => '34', LANGUAGE_CODE => 'es', CURRENCY => 'USD'],
				self::ERITREA => [COUNTRY_NAME => 'Eritrea', COUNTRY_CODE => 'ER', COUNTRY_PHONE => '291', LANGUAGE_CODE => 'ti', CURRENCY => 'USD'],
				self::MONTENEGRO => [COUNTRY_NAME => 'Montenegro', COUNTRY_CODE => 'ME', COUNTRY_PHONE => '382', LANGUAGE_CODE => 'sr', CURRENCY => 'USD'],
				self::MOLDOVA => [COUNTRY_NAME => 'Moldova', COUNTRY_CODE => 'MD', COUNTRY_PHONE => '373', LANGUAGE_CODE => 'ro', CURRENCY => 'USD'],
				self::MADAGASCAR => [COUNTRY_NAME => 'Madagascar', COUNTRY_CODE => 'MG', COUNTRY_PHONE => '261', LANGUAGE_CODE => 'fr', CURRENCY => 'USD'],
				self::SAINT_MARTIN => [COUNTRY_NAME => 'Saint Martin', COUNTRY_CODE => 'MF', COUNTRY_PHONE => '590', LANGUAGE_CODE => 'fr', CURRENCY => 'USD'],
				self::MOROCCO => [COUNTRY_NAME => 'Morocco', COUNTRY_CODE => 'MA', COUNTRY_PHONE => '212', LANGUAGE_CODE => 'ar', CURRENCY => 'MAD'],
				self::MONACO => [COUNTRY_NAME => 'Monaco', COUNTRY_CODE => 'MC', COUNTRY_PHONE => '377', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR'],
				self::UZBEKISTAN => [COUNTRY_NAME => 'Uzbekistan', COUNTRY_CODE => 'UZ', COUNTRY_PHONE => '998', LANGUAGE_CODE => 'uz', CURRENCY => 'USD'],
				self::MYANMAR => [COUNTRY_NAME => 'Myanmar', COUNTRY_CODE => 'MM', COUNTRY_PHONE => '95', LANGUAGE_CODE => 'my', CURRENCY => 'USD'],
				self::MALI => [COUNTRY_NAME => 'Mali', COUNTRY_CODE => 'ML', COUNTRY_PHONE => '223', LANGUAGE_CODE => 'fr', CURRENCY => 'USD'],
				self::MACAO => [COUNTRY_NAME => 'Macao', COUNTRY_CODE => 'MO', COUNTRY_PHONE => '853', LANGUAGE_CODE => 'pt', CURRENCY => 'USD'],
				self::MONGOLIA => [COUNTRY_NAME => 'Mongolia', COUNTRY_CODE => 'MN', COUNTRY_PHONE => '976', LANGUAGE_CODE => 'mn', CURRENCY => 'USD'],
				self::MARSHALL_ISLANDS => [COUNTRY_NAME => 'Marshall Islands', COUNTRY_CODE => 'MH', COUNTRY_PHONE => '692', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::MACEDONIA => [COUNTRY_NAME => 'Macedonia', COUNTRY_CODE => 'MK', COUNTRY_PHONE => '389', LANGUAGE_CODE => 'mk', CURRENCY => 'USD'],
				self::MAURITIUS => [COUNTRY_NAME => 'Mauritius', COUNTRY_CODE => 'MU', COUNTRY_PHONE => '230', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::MALTA => [COUNTRY_NAME => 'Malta', COUNTRY_CODE => 'MT', COUNTRY_PHONE => '356', LANGUAGE_CODE => 'mt', CURRENCY => 'USD'],
				self::MALAWI => [COUNTRY_NAME => 'Malawi', COUNTRY_CODE => 'MW', COUNTRY_PHONE => '265', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::MALDIVES => [COUNTRY_NAME => 'Maldives', COUNTRY_CODE => 'MV', COUNTRY_PHONE => '960', LANGUAGE_CODE => 'dv', CURRENCY => 'USD'],
				self::MARTINIQUE => [COUNTRY_NAME => 'Martinique', COUNTRY_CODE => 'MQ', COUNTRY_PHONE => '596', LANGUAGE_CODE => 'fr', CURRENCY => 'USD'],
				self::NORTHERN_MARIANA_ISLANDS => [COUNTRY_NAME => 'Northern Mariana Islands', COUNTRY_CODE => 'MP', COUNTRY_PHONE => '1-670', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::MONTSERRAT => [COUNTRY_NAME => 'Montserrat', COUNTRY_CODE => 'MS', COUNTRY_PHONE => '1-664', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::MAURITANIA => [COUNTRY_NAME => 'Mauritania', COUNTRY_CODE => 'MR', COUNTRY_PHONE => '222', LANGUAGE_CODE => 'ar', CURRENCY => 'USD'],
				self::ISLE_OF_MAN => [COUNTRY_NAME => 'Isle of Man', COUNTRY_CODE => 'IM', COUNTRY_PHONE => '44-1624', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::UGANDA => [COUNTRY_NAME => 'Uganda', COUNTRY_CODE => 'UG', COUNTRY_PHONE => '256', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::TANZANIA => [COUNTRY_NAME => 'Tanzania', COUNTRY_CODE => 'TZ', COUNTRY_PHONE => '255', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::MALAYSIA => [COUNTRY_NAME => 'Malaysia', COUNTRY_CODE => 'MY', COUNTRY_PHONE => '60', LANGUAGE_CODE => 'ms', CURRENCY => 'MYR'],
				self::MEXICO => [COUNTRY_NAME => 'Mexico', COUNTRY_CODE => 'MX', COUNTRY_PHONE => '52', LANGUAGE_CODE => 'es', CURRENCY => 'MXN'],
				self::ISRAEL => [COUNTRY_NAME => 'Israel', COUNTRY_CODE => 'IL', COUNTRY_PHONE => '972', LANGUAGE_CODE => 'he', CURRENCY => 'ILS'],
				self::FRANCE => [COUNTRY_NAME => 'France', COUNTRY_CODE => 'FR', COUNTRY_PHONE => '33', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR'],
				self::BRITISH_INDIAN_OCEAN_TERRITORY => [COUNTRY_NAME => 'British Indian Ocean Territory', COUNTRY_CODE => 'IO', COUNTRY_PHONE => '246', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::SAINT_HELENA => [COUNTRY_NAME => 'Saint Helena', COUNTRY_CODE => 'SH', COUNTRY_PHONE => '290', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::FINLAND => [COUNTRY_NAME => 'Finland', COUNTRY_CODE => 'FI', COUNTRY_PHONE => '358', LANGUAGE_CODE => 'fi', CURRENCY => 'EUR'],
				self::FIJI => [COUNTRY_NAME => 'Fiji', COUNTRY_CODE => 'FJ', COUNTRY_PHONE => '679', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::FALKLAND_ISLANDS => [COUNTRY_NAME => 'Falkland Islands', COUNTRY_CODE => 'FK', COUNTRY_PHONE => '500', LANGUAGE_CODE => 'en', CURRENCY => 'FKP'],
				self::MICRONESIA => [COUNTRY_NAME => 'Micronesia', COUNTRY_CODE => 'FM', COUNTRY_PHONE => '691', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::FAROE_ISLANDS => [COUNTRY_NAME => 'Faroe Islands', COUNTRY_CODE => 'FO', COUNTRY_PHONE => '298', LANGUAGE_CODE => 'fo', CURRENCY => 'DKK'],
				self::NICARAGUA => [COUNTRY_NAME => 'Nicaragua', COUNTRY_CODE => 'NI', COUNTRY_PHONE => '505', LANGUAGE_CODE => 'es', CURRENCY => 'USD'],
				self::NETHERLANDS => [COUNTRY_NAME => 'Netherlands', COUNTRY_CODE => 'NL', COUNTRY_PHONE => '31', LANGUAGE_CODE => 'nl', CURRENCY => 'EUR'],
				self::NORWAY => [COUNTRY_NAME => 'Norway', COUNTRY_CODE => 'NO', COUNTRY_PHONE => '47', LANGUAGE_CODE => 'no', CURRENCY => 'NOK'],
				self::NAMIBIA => [COUNTRY_NAME => 'Namibia', COUNTRY_CODE => 'NA', COUNTRY_PHONE => '264', LANGUAGE_CODE => 'en', CURRENCY => 'NAD'],
				self::VANUATU => [COUNTRY_NAME => 'Vanuatu', COUNTRY_CODE => 'VU', COUNTRY_PHONE => '678', LANGUAGE_CODE => 'en', CURRENCY => 'VUV'],
				self::NEW_CALEDONIA => [COUNTRY_NAME => 'New Caledonia', COUNTRY_CODE => 'NC', COUNTRY_PHONE => '687', LANGUAGE_CODE => 'fr', CURRENCY => 'XPF'],
				self::NIGER => [COUNTRY_NAME => 'Niger', COUNTRY_CODE => 'NE', COUNTRY_PHONE => '227', LANGUAGE_CODE => 'fr', CURRENCY => 'XOF'],
				self::NORFOLK_ISLAND => [COUNTRY_NAME => 'Norfolk Island', COUNTRY_CODE => 'NF', COUNTRY_PHONE => '672', LANGUAGE_CODE => 'en', CURRENCY => 'AUD'],
				self::NIGERIA => [COUNTRY_NAME => 'Nigeria', COUNTRY_CODE => 'NG', COUNTRY_PHONE => '234', LANGUAGE_CODE => 'en', CURRENCY => 'NGN'],
				self::NEW_ZEALAND => [COUNTRY_NAME => 'New Zealand', COUNTRY_CODE => 'NZ', COUNTRY_PHONE => '64', LANGUAGE_CODE => 'en', CURRENCY => 'NZD'],
				self::NEPAL => [COUNTRY_NAME => 'Nepal', COUNTRY_CODE => 'NP', COUNTRY_PHONE => '977', LANGUAGE_CODE => 'ne', CURRENCY => 'NPR'],
				self::NAURU => [COUNTRY_NAME => 'Nauru', COUNTRY_CODE => 'NR', COUNTRY_PHONE => '674', LANGUAGE_CODE => 'na', CURRENCY => 'AUD'],
				self::NIUE => [COUNTRY_NAME => 'Niue', COUNTRY_CODE => 'NU', COUNTRY_PHONE => '683', LANGUAGE_CODE => 'niu', CURRENCY => 'NZD'],
				self::COOK_ISLANDS => [COUNTRY_NAME => 'Cook Islands', COUNTRY_CODE => 'CK', COUNTRY_PHONE => '682', LANGUAGE_CODE => 'en', CURRENCY => 'NZD'],
				self::KOSOVO => [COUNTRY_NAME => 'Kosovo', COUNTRY_CODE => 'XK', COUNTRY_PHONE => '', LANGUAGE_CODE => 'sq', CURRENCY => 'EUR'],
				self::IVORY_COAST => [COUNTRY_NAME => 'Ivory Coast', COUNTRY_CODE => 'CI', COUNTRY_PHONE => '225', LANGUAGE_CODE => 'fr', CURRENCY => 'XOF'],
				self::SWITZERLAND => [COUNTRY_NAME => 'Switzerland', COUNTRY_CODE => 'CH', COUNTRY_PHONE => '41', LANGUAGE_CODE => 'de', CURRENCY => 'CHF'],
				self::COLOMBIA => [COUNTRY_NAME => 'Colombia', COUNTRY_CODE => 'CO', COUNTRY_PHONE => '57', LANGUAGE_CODE => 'es', CURRENCY => 'COP'],
				self::CHINA => [COUNTRY_NAME => 'China', COUNTRY_CODE => 'CN', COUNTRY_PHONE => '86', LANGUAGE_CODE => 'zh', CURRENCY => 'CNY'],
				self::CAMEROON => [COUNTRY_NAME => 'Cameroon', COUNTRY_CODE => 'CM', COUNTRY_PHONE => '237', LANGUAGE_CODE => 'fr', CURRENCY => 'XAF'],
				self::CHILE => [COUNTRY_NAME => 'Chile', COUNTRY_CODE => 'CL', COUNTRY_PHONE => '56', LANGUAGE_CODE => 'es', CURRENCY => 'CLP'],
				self::COCOS_ISLANDS => [COUNTRY_NAME => 'Cocos Islands', COUNTRY_CODE => 'CC', COUNTRY_PHONE => '61', LANGUAGE_CODE => 'en', CURRENCY => 'AUD'],
				self::CANADA => [COUNTRY_NAME => 'Canada', COUNTRY_CODE => 'CA', COUNTRY_PHONE => '1', LANGUAGE_CODE => 'en', CURRENCY => 'CAD'],
				self::REPUBLIC_OF_THE_CONGO => [COUNTRY_NAME => 'Republic of the Congo', COUNTRY_CODE => 'CG', COUNTRY_PHONE => '242', LANGUAGE_CODE => 'fr', CURRENCY => 'XAF'],
				self::CENTRAL_AFRICAN_REPUBLIC => [COUNTRY_NAME => 'Central African Republic', COUNTRY_CODE => 'CF', COUNTRY_PHONE => '236', LANGUAGE_CODE => 'fr', CURRENCY => 'XAF'],
				self::DEMOCRATIC_REPUBLIC_OF_THE_CONGO => [COUNTRY_NAME => 'Democratic Republic of the Congo', COUNTRY_CODE => 'CD', COUNTRY_PHONE => '243', LANGUAGE_CODE => 'fr', CURRENCY => 'CDF'],
				self::CZECH_REPUBLIC => [COUNTRY_NAME => 'Czech Republic', COUNTRY_CODE => 'CZ', COUNTRY_PHONE => '420', LANGUAGE_CODE => 'cs', CURRENCY => 'CZK'],
				self::CYPRUS => [COUNTRY_NAME => 'Cyprus', COUNTRY_CODE => 'CY', COUNTRY_PHONE => '357', LANGUAGE_CODE => 'el', CURRENCY => 'EUR'],
				self::CHRISTMAS_ISLAND => [COUNTRY_NAME => 'Christmas Island', COUNTRY_CODE => 'CX', COUNTRY_PHONE => '61', LANGUAGE_CODE => 'en', CURRENCY => 'AUD'],
				self::COSTA_RICA => [COUNTRY_NAME => 'Costa Rica', COUNTRY_CODE => 'CR', COUNTRY_PHONE => '506', LANGUAGE_CODE => 'es', CURRENCY => 'CRC'],
				self::CURACAO => [COUNTRY_NAME => 'Curacao', COUNTRY_CODE => 'CW', COUNTRY_PHONE => '599', LANGUAGE_CODE => 'nl', CURRENCY => 'ANG'],
				self::CAPE_VERDE => [COUNTRY_NAME => 'Cape Verde', COUNTRY_CODE => 'CV', COUNTRY_PHONE => '238', LANGUAGE_CODE => 'pt', CURRENCY => 'CVE'],
				self::CUBA => [COUNTRY_NAME => 'Cuba', COUNTRY_CODE => 'CU', COUNTRY_PHONE => '53', LANGUAGE_CODE => 'es', CURRENCY => 'CUP'],
				self::SWAZILAND => [COUNTRY_NAME => 'Swaziland', COUNTRY_CODE => 'SZ', COUNTRY_PHONE => '268', LANGUAGE_CODE => 'en', CURRENCY => 'SZL'],
				self::SYRIA => [COUNTRY_NAME => 'Syria', COUNTRY_CODE => 'SY', COUNTRY_PHONE => '963', LANGUAGE_CODE => 'ar', CURRENCY => 'SYP'],
				self::SINT_MAARTEN => [COUNTRY_NAME => 'Sint Maarten', COUNTRY_CODE => 'SX', COUNTRY_PHONE => '599', LANGUAGE_CODE => 'en', CURRENCY => 'ANG'],
				self::KYRGYZSTAN => [COUNTRY_NAME => 'Kyrgyzstan', COUNTRY_CODE => 'KG', COUNTRY_PHONE => '996', LANGUAGE_CODE => 'ky', CURRENCY => 'KGS'],
				self::KENYA => [COUNTRY_NAME => 'Kenya', COUNTRY_CODE => 'KE', COUNTRY_PHONE => '254', LANGUAGE_CODE => 'en', CURRENCY => 'KES'],
				self::SOUTH_SUDAN => [COUNTRY_NAME => 'South Sudan', COUNTRY_CODE => 'SS', COUNTRY_PHONE => '211', LANGUAGE_CODE => 'ar', CURRENCY => 'SSP'],
				self::SURINAME => [COUNTRY_NAME => 'Suriname', COUNTRY_CODE => 'SR', COUNTRY_PHONE => '597', LANGUAGE_CODE => 'nl', CURRENCY => 'SRD'],
				self::KIRIBATI => [COUNTRY_NAME => 'Kiribati', COUNTRY_CODE => 'KI', COUNTRY_PHONE => '686', LANGUAGE_CODE => 'en', CURRENCY => 'AUD'],
				self::CAMBODIA => [COUNTRY_NAME => 'Cambodia', COUNTRY_CODE => 'KH', COUNTRY_PHONE => '855', LANGUAGE_CODE => 'km', CURRENCY => 'KHR'],
				self::SAINT_KITTS_AND_NEVIS => [COUNTRY_NAME => 'Saint Kitts and Nevis', COUNTRY_CODE => 'KN', COUNTRY_PHONE => '1-869', LANGUAGE_CODE => 'en', CURRENCY => 'XCD'],
				self::COMOROS => [COUNTRY_NAME => 'Comoros', COUNTRY_CODE => 'KM', COUNTRY_PHONE => '269', LANGUAGE_CODE => 'fr', CURRENCY => 'KMF'],
				self::SAO_TOME_AND_PRINCIPE => [COUNTRY_NAME => 'Sao Tome and Principe', COUNTRY_CODE => 'ST', COUNTRY_PHONE => '239', LANGUAGE_CODE => 'pt', CURRENCY => 'STN'],
				self::SLOVAKIA => [COUNTRY_NAME => 'Slovakia', COUNTRY_CODE => 'SK', COUNTRY_PHONE => '421', LANGUAGE_CODE => 'sk', CURRENCY => 'EUR'],
				self::SOUTH_KOREA => [COUNTRY_NAME => 'South Korea', COUNTRY_CODE => 'KR', COUNTRY_PHONE => '82', LANGUAGE_CODE => 'ko', CURRENCY => 'KRW'],
				self::SLOVENIA => [COUNTRY_NAME => 'Slovenia', COUNTRY_CODE => 'SI', COUNTRY_PHONE => '386', LANGUAGE_CODE => 'sl', CURRENCY => 'EUR'],
				self::NORTH_KOREA => [COUNTRY_NAME => 'North Korea', COUNTRY_CODE => 'KP', COUNTRY_PHONE => '850', LANGUAGE_CODE => 'ko', CURRENCY => 'KPW'],
				self::KUWAIT => [COUNTRY_NAME => 'Kuwait', COUNTRY_CODE => 'KW', COUNTRY_PHONE => '965', LANGUAGE_CODE => 'ar', CURRENCY => 'KWD'],
				self::SENEGAL => [COUNTRY_NAME => 'Senegal', COUNTRY_CODE => 'SN', COUNTRY_PHONE => '221', LANGUAGE_CODE => 'fr', CURRENCY => 'XOF'],
				self::SAN_MARINO => [COUNTRY_NAME => 'San Marino', COUNTRY_CODE => 'SM', COUNTRY_PHONE => '378', LANGUAGE_CODE => 'it', CURRENCY => 'EUR'],
				self::SIERRA_LEONE => [COUNTRY_NAME => 'Sierra Leone', COUNTRY_CODE => 'SL', COUNTRY_PHONE => '232', LANGUAGE_CODE => 'en', CURRENCY => 'SLL'],
				self::SEYCHELLES => [COUNTRY_NAME => 'Seychelles', COUNTRY_CODE => 'SC', COUNTRY_PHONE => '248', LANGUAGE_CODE => 'fr', CURRENCY => 'SCR'],
				self::KAZAKHSTAN => [COUNTRY_NAME => 'Kazakhstan', COUNTRY_CODE => 'KZ', COUNTRY_PHONE => '7', LANGUAGE_CODE => 'kk', CURRENCY => 'KZT'],
				self::CAYMAN_ISLANDS => [COUNTRY_NAME => 'Cayman Islands', COUNTRY_CODE => 'KY', COUNTRY_PHONE => '1-345', LANGUAGE_CODE => 'en', CURRENCY => 'KYD'],
				self::SINGAPORE => [COUNTRY_NAME => 'Singapore', COUNTRY_CODE => 'SG', COUNTRY_PHONE => '65', LANGUAGE_CODE => 'en', CURRENCY => 'SGD'],
				self::SWEDEN => [COUNTRY_NAME => 'Sweden', COUNTRY_CODE => 'SE', COUNTRY_PHONE => '46', LANGUAGE_CODE => 'sv', CURRENCY => 'SEK'],
				self::SUDAN => [COUNTRY_NAME => 'Sudan', COUNTRY_CODE => 'SD', COUNTRY_PHONE => '249', LANGUAGE_CODE => 'ar', CURRENCY => 'SDG'],
				self::DOMINICAN_REPUBLIC => [COUNTRY_NAME => 'Dominican Republic', COUNTRY_CODE => 'DO', COUNTRY_PHONE => '1-809 and 1-829', LANGUAGE_CODE => 'es', CURRENCY => 'DOP'],
				self::DOMINICA => [COUNTRY_NAME => 'Dominica', COUNTRY_CODE => 'DM', COUNTRY_PHONE => '1-767', LANGUAGE_CODE => 'en', CURRENCY => 'XCD'],
				self::DJIBOUTI => [COUNTRY_NAME => 'Djibouti', COUNTRY_CODE => 'DJ', COUNTRY_PHONE => '253', LANGUAGE_CODE => 'fr', CURRENCY => 'DJF'],
				self::DENMARK => [COUNTRY_NAME => 'Denmark', COUNTRY_CODE => 'DK', COUNTRY_PHONE => '45', LANGUAGE_CODE => 'da', CURRENCY => 'DKK'],
				self::BRITISH_VIRGIN_ISLANDS => [COUNTRY_NAME => 'British Virgin Islands', COUNTRY_CODE => 'VG', COUNTRY_PHONE => '1-284', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::GERMANY => [COUNTRY_NAME => 'Germany', COUNTRY_CODE => 'DE', COUNTRY_PHONE => '49', LANGUAGE_CODE => 'de', CURRENCY => 'EUR'],
				self::YEMEN => [COUNTRY_NAME => 'Yemen', COUNTRY_CODE => 'YE', COUNTRY_PHONE => '967', LANGUAGE_CODE => 'ar', CURRENCY => 'YER'],
				self::ALGERIA => [COUNTRY_NAME => 'Algeria', COUNTRY_CODE => 'DZ', COUNTRY_PHONE => '213', LANGUAGE_CODE => 'ar', CURRENCY => 'DZD'],
				self::UNITED_STATES => [COUNTRY_NAME => 'United States', COUNTRY_CODE => 'US', COUNTRY_PHONE => '1', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::URUGUAY => [COUNTRY_NAME => 'Uruguay', COUNTRY_CODE => 'UY', COUNTRY_PHONE => '598', LANGUAGE_CODE => 'es', CURRENCY => 'UYU'],
				self::MAYOTTE => [COUNTRY_NAME => 'Mayotte', COUNTRY_CODE => 'YT', COUNTRY_PHONE => '262', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR'],
				self::UNITED_STATES_MINOR_OUTLYING_ISLANDS => [COUNTRY_NAME => 'United States Minor Outlying Islands', COUNTRY_CODE => 'UM', COUNTRY_PHONE => '1', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::LEBANON => [COUNTRY_NAME => 'Lebanon', COUNTRY_CODE => 'LB', COUNTRY_PHONE => '961', LANGUAGE_CODE => 'ar', CURRENCY => 'LBP'],
				self::SAINT_LUCIA => [COUNTRY_NAME => 'Saint Lucia', COUNTRY_CODE => 'LC', COUNTRY_PHONE => '1-758', LANGUAGE_CODE => 'en', CURRENCY => 'XCD'],
				self::LAOS => [COUNTRY_NAME => 'Laos', COUNTRY_CODE => 'LA', COUNTRY_PHONE => '856', LANGUAGE_CODE => 'lo', CURRENCY => 'LAK'],
				self::TUVALU => [COUNTRY_NAME => 'Tuvalu', COUNTRY_CODE => 'TV', COUNTRY_PHONE => '688', LANGUAGE_CODE => 'tv', CURRENCY => 'AUD'],
				self::TAIWAN => [COUNTRY_NAME => 'Taiwan', COUNTRY_CODE => 'TW', COUNTRY_PHONE => '886', LANGUAGE_CODE => 'zh', CURRENCY => 'TWD'],
				self::TRINIDAD_AND_TOBAGO => [COUNTRY_NAME => 'Trinidad and Tobago', COUNTRY_CODE => 'TT', COUNTRY_PHONE => '1-868', LANGUAGE_CODE => 'en', CURRENCY => 'TTD'],
				self::TURKEY => [COUNTRY_NAME => 'Turkey', COUNTRY_CODE => 'TR', COUNTRY_PHONE => '90', LANGUAGE_CODE => 'tr', CURRENCY => 'TRY'],
				self::SRI_LANKA => [COUNTRY_NAME => 'Sri Lanka', COUNTRY_CODE => 'LK', COUNTRY_PHONE => '94', LANGUAGE_CODE => 'si', CURRENCY => 'LKR'],
				self::LIECHTENSTEIN => [COUNTRY_NAME => 'Liechtenstein', COUNTRY_CODE => 'LI', COUNTRY_PHONE => '423', LANGUAGE_CODE => 'de', CURRENCY => 'CHF'],
				self::LATVIA => [COUNTRY_NAME => 'Latvia', COUNTRY_CODE => 'LV', COUNTRY_PHONE => '371', LANGUAGE_CODE => 'lv', CURRENCY => 'EUR'],
				self::TONGA => [COUNTRY_NAME => 'Tonga', COUNTRY_CODE => 'TO', COUNTRY_PHONE => '676', LANGUAGE_CODE => 'to', CURRENCY => 'TOP'],
				self::LITHUANIA => [COUNTRY_NAME => 'Lithuania', COUNTRY_CODE => 'LT', COUNTRY_PHONE => '370', LANGUAGE_CODE => 'lt', CURRENCY => 'EUR'],
				self::LUXEMBOURG => [COUNTRY_NAME => 'Luxembourg', COUNTRY_CODE => 'LU', COUNTRY_PHONE => '352', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR'],
				self::LIBERIA => [COUNTRY_NAME => 'Liberia', COUNTRY_CODE => 'LR', COUNTRY_PHONE => '231', LANGUAGE_CODE => 'en', CURRENCY => 'LRD'],
				self::LESOTHO => [COUNTRY_NAME => 'Lesotho', COUNTRY_CODE => 'LS', COUNTRY_PHONE => '266', LANGUAGE_CODE => 'en', CURRENCY => 'LSL'],
				self::THAILAND => [COUNTRY_NAME => 'Thailand', COUNTRY_CODE => 'TH', COUNTRY_PHONE => '66', LANGUAGE_CODE => 'th', CURRENCY => 'THB'],
				self::FRENCH_SOUTHERN_TERRITORIES => [COUNTRY_NAME => 'French Southern Territories', COUNTRY_CODE => 'TF', COUNTRY_PHONE => '', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR'],
				self::TOGO => [COUNTRY_NAME => 'Togo', COUNTRY_CODE => 'TG', COUNTRY_PHONE => '228', LANGUAGE_CODE => 'fr', CURRENCY => 'XOF'],
				self::CHAD => [COUNTRY_NAME => 'Chad', COUNTRY_CODE => 'TD', COUNTRY_PHONE => '235', LANGUAGE_CODE => 'fr', CURRENCY => 'XAF'],
				self::TURKS_AND_CAICOS_ISLANDS => [COUNTRY_NAME => 'Turks and Caicos Islands', COUNTRY_CODE => 'TC', COUNTRY_PHONE => '1-649', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::LIBYA => [COUNTRY_NAME => 'Libya', COUNTRY_CODE => 'LY', COUNTRY_PHONE => '218', LANGUAGE_CODE => 'ar', CURRENCY => 'USD'],
				self::VATICAN => [COUNTRY_NAME => 'Vatican', COUNTRY_CODE => 'VA', COUNTRY_PHONE => '379', LANGUAGE_CODE => 'it', CURRENCY => 'EUR'],
				self::SAINT_VINCENT_AND_THE_GRENADINES => [COUNTRY_NAME => 'Saint Vincent and the Grenadines', COUNTRY_CODE => 'VC', COUNTRY_PHONE => '1-784', LANGUAGE_CODE => 'en', CURRENCY => 'XCD'],
				self::UNITED_ARAB_EMIRATES => [COUNTRY_NAME => 'United Arab Emirates', COUNTRY_CODE => 'AE', COUNTRY_PHONE => '971', LANGUAGE_CODE => 'ar', CURRENCY => 'AED'],
				self::ANDORRA => [COUNTRY_NAME => 'Andorra', COUNTRY_CODE => 'AD', COUNTRY_PHONE => '376', LANGUAGE_CODE => 'ca', CURRENCY => 'EUR'],
				self::ANTIGUA_AND_BARBUDA => [COUNTRY_NAME => 'Antigua and Barbuda', COUNTRY_CODE => 'AG', COUNTRY_PHONE => '1-268', LANGUAGE_CODE => 'en', CURRENCY => 'XCD'],
				self::AFGHANISTAN => [COUNTRY_NAME => 'Afghanistan', COUNTRY_CODE => 'AF', COUNTRY_PHONE => '93', LANGUAGE_CODE => 'ps', CURRENCY => 'AFN'],
				self::ANGUILLA => [COUNTRY_NAME => 'Anguilla', COUNTRY_CODE => 'AI', COUNTRY_PHONE => '1-264', LANGUAGE_CODE => 'en', CURRENCY => 'XCD'],
				self::US_VIRGIN_ISLANDS => [COUNTRY_NAME => 'U.S. Virgin Islands', COUNTRY_CODE => 'VI', COUNTRY_PHONE => '1-340', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::ICELAND => [COUNTRY_NAME => 'Iceland', COUNTRY_CODE => 'IS', COUNTRY_PHONE => '354', LANGUAGE_CODE => 'is', CURRENCY => 'ISK'],
				self::IRAN => [COUNTRY_NAME => 'Iran', COUNTRY_CODE => 'IR', COUNTRY_PHONE => '98', LANGUAGE_CODE => 'fa', CURRENCY => 'IRR'],
				self::ARMENIA => [COUNTRY_NAME => 'Armenia', COUNTRY_CODE => 'AM', COUNTRY_PHONE => '374', LANGUAGE_CODE => 'hy', CURRENCY => 'AMD'],
				self::ALBANIA => [COUNTRY_NAME => 'Albania', COUNTRY_CODE => 'AL', COUNTRY_PHONE => '355', LANGUAGE_CODE => 'sq', CURRENCY => 'ALL'],
				self::ANGOLA => [COUNTRY_NAME => 'Angola', COUNTRY_CODE => 'AO', COUNTRY_PHONE => '244', LANGUAGE_CODE => 'pt', CURRENCY => 'AOA'],
				self::ANTARCTICA => [COUNTRY_NAME => 'Antarctica', COUNTRY_CODE => 'AQ', COUNTRY_PHONE => '', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::AMERICAN_SAMOA => [COUNTRY_NAME => 'American Samoa', COUNTRY_CODE => 'AS', COUNTRY_PHONE => '1-684', LANGUAGE_CODE => 'en', CURRENCY => 'USD'],
				self::ARGENTINA => [COUNTRY_NAME => 'Argentina', COUNTRY_CODE => 'AR', COUNTRY_PHONE => '54', LANGUAGE_CODE => 'es', CURRENCY => 'ARS'],
				self::AUSTRALIA => [COUNTRY_NAME => 'Australia', COUNTRY_CODE => 'AU', COUNTRY_PHONE => '61', LANGUAGE_CODE => 'en', CURRENCY => 'AUD'],
				self::AUSTRIA => [COUNTRY_NAME => 'Austria', COUNTRY_CODE => 'AT', COUNTRY_PHONE => '43', LANGUAGE_CODE => 'de', CURRENCY => 'EUR'],
				self::ARUBA => [COUNTRY_NAME => 'Aruba', COUNTRY_CODE => 'AW', COUNTRY_PHONE => '297', LANGUAGE_CODE => 'nl', CURRENCY => 'AWG'],
				self::INDIA => [COUNTRY_NAME => 'India', COUNTRY_CODE => 'IN', COUNTRY_PHONE => '91', LANGUAGE_CODE => 'en', CURRENCY => 'INR'],
				self::ALAND_ISLANDS => [COUNTRY_NAME => 'Aland Islands', COUNTRY_CODE => 'AX', COUNTRY_PHONE => '358-18', LANGUAGE_CODE => 'sv', CURRENCY => 'EUR'],
				self::AZERBAIJAN => [COUNTRY_NAME => 'Azerbaijan', COUNTRY_CODE => 'AZ', COUNTRY_PHONE => '994', LANGUAGE_CODE => 'az', CURRENCY => 'AZN'],
				self::IRELAND => [COUNTRY_NAME => 'Ireland', COUNTRY_CODE => 'IE', COUNTRY_PHONE => '353', LANGUAGE_CODE => 'en', CURRENCY => 'EUR'],
				self::INDONESIA => [COUNTRY_NAME => 'Indonesia', COUNTRY_CODE => 'ID', COUNTRY_PHONE => '62', LANGUAGE_CODE => 'id', CURRENCY => 'IDR'],
				self::UKRAINE => [COUNTRY_NAME => 'Ukraine', COUNTRY_CODE => 'UA', COUNTRY_PHONE => '380', LANGUAGE_CODE => 'uk', CURRENCY => 'UAH'],
				self::QATAR => [COUNTRY_NAME => 'Qatar', COUNTRY_CODE => 'QA', COUNTRY_PHONE => '974', LANGUAGE_CODE => 'ar', CURRENCY => 'QAR'],
				self::MOZAMBIQUE => [COUNTRY_NAME => 'Mozambique', COUNTRY_CODE => 'MZ', COUNTRY_PHONE => '258', LANGUAGE_CODE => 'pt', CURRENCY => 'USD']
			};

			return (empty($_type)) ? $country : $country[$_type];
		}

		public static function find(string $_code): self
		{
			$_code = strtoupper($_code);

			return match($_code)
			{
				'BD' => self::BANGLADESH,
				'BE' => self::BELGIUM,
				'BF' => self::BURKINA_FASO,
				'BG' => self::BULGARIA,
				'BA' => self::BOSNIA_AND_HERZEGOVINA,
				'BB' => self::BARBADOS,
				'WF' => self::WALLIS_AND_FUTUNA,
				'BL' => self::SAINT_BARTHELEMY,
				'BM' => self::BERMUDA,
				'BN' => self::BRUNEI,
				'BO' => self::BOLIVIA,
				'BH' => self::BAHRAIN,
				'BI' => self::BURUNDI,
				'BJ' => self::BENIN,
				'BT' => self::BHUTAN,
				'JM' => self::JAMAICA,
				'BV' => self::BOUVET_ISLAND,
				'BW' => self::BOTSWANA,
				'WS' => self::SAMOA,
				'BQ' => self::BONAIRE_SAINT_EUSTATIUS_AND_SABA,
				'BR' => self::BRAZIL,
				'BS' => self::BAHAMAS,
				'JE' => self::JERSEY,
				'BY' => self::BELARUS,
				'BZ' => self::BELIZE,
				'RU' => self::RUSSIA,
				'RW' => self::RWANDA,
				'RS' => self::SERBIA,
				'TL' => self::EAST_TIMOR,
				'RE' => self::REUNION,
				'TM' => self::TURKMENISTAN,
				'TJ' => self::TAJIKISTAN,
				'RO' => self::ROMANIA,
				'TK' => self::TOKELAU,
				'GW' => self::GUINEA_BISSAU,
				'GU' => self::GUAM,
				'GT' => self::GUATEMALA,
				'GS' => self::SOUTH_GEORGIA_AND_THE_SOUTH_SANDWICH_ISLANDS,
				'GR' => self::GREECE,
				'GQ' => self::EQUATORIAL_GUINEA,
				'GP' => self::GUADELOUPE,
				'JP' => self::JAPAN,
				'GY' => self::GUYANA,
				'GG' => self::GUERNSEY,
				'GF' => self::FRENCH_GUIANA,
				'GE' => self::GEORGIA,
				'GD' => self::GRENADA,
				'GB' => self::UNITED_KINGDOM,
				'GA' => self::GABON,
				'SV' => self::EL_SALVADOR,
				'GN' => self::GUINEA,
				'GM' => self::GAMBIA,
				'GL' => self::GREENLAND,
				'GI' => self::GIBRALTAR,
				'GH' => self::GHANA,
				'OM' => self::OMAN,
				'TN' => self::TUNISIA,
				'JO' => self::JORDAN,
				'HR' => self::CROATIA,
				'HT' => self::HAITI,
				'HU' => self::HUNGARY,
				'HK' => self::HONG_KONG,
				'HN' => self::HONDURAS,
				'HM' => self::HEARD_ISLAND_AND_MCDONALD_ISLANDS,
				'VE' => self::VENEZUELA,
				'PR' => self::PUERTO_RICO,
				'PS' => self::PALESTINIAN_TERRITORY,
				'PW' => self::PALAU,
				'PT' => self::PORTUGAL,
				'SJ' => self::SVALBARD_AND_JAN_MAYEN,
				'PY' => self::PARAGUAY,
				'IQ' => self::IRAQ,
				'PA' => self::PANAMA,
				'PF' => self::FRENCH_POLYNESIA,
				'PG' => self::PAPUA_NEW_GUINEA,
				'PE' => self::PERU,
				'PK' => self::PAKISTAN,
				'PH' => self::PHILIPPINES,
				'PN' => self::PITCAIRN,
				'PL' => self::POLAND,
				'PM' => self::SAINT_PIERRE_AND_MIQUELON,
				'ZM' => self::ZAMBIA,
				'EH' => self::WESTERN_SAHARA,
				'EE' => self::ESTONIA,
				'EG' => self::EGYPT,
				'ZA' => self::SOUTH_AFRICA,
				'EC' => self::ECUADOR,
				'IT' => self::ITALY,
				'VN' => self::VIETNAM,
				'SB' => self::SOLOMON_ISLANDS,
				'ET' => self::ETHIOPIA,
				'SO' => self::SOMALIA,
				'ZW' => self::ZIMBABWE,
				'SA' => self::SAUDI_ARABIA,
				'ES' => self::SPAIN,
				'ER' => self::ERITREA,
				'ME' => self::MONTENEGRO,
				'MD' => self::MOLDOVA,
				'MG' => self::MADAGASCAR,
				'MF' => self::SAINT_MARTIN,
				'MA' => self::MOROCCO,
				'MC' => self::MONACO,
				'UZ' => self::UZBEKISTAN,
				'MM' => self::MYANMAR,
				'ML' => self::MALI,
				'MO' => self::MACAO,
				'MN' => self::MONGOLIA,
				'MH' => self::MARSHALL_ISLANDS,
				'MK' => self::MACEDONIA,
				'MU' => self::MAURITIUS,
				'MT' => self::MALTA,
				'MW' => self::MALAWI,
				'MV' => self::MALDIVES,
				'MQ' => self::MARTINIQUE,
				'MP' => self::NORTHERN_MARIANA_ISLANDS,
				'MS' => self::MONTSERRAT,
				'MR' => self::MAURITANIA,
				'IM' => self::ISLE_OF_MAN,
				'UG' => self::UGANDA,
				'TZ' => self::TANZANIA,
				'MY' => self::MALAYSIA,
				'MX' => self::MEXICO,
				'IL' => self::ISRAEL,
				'FR' => self::FRANCE,
				'IO' => self::BRITISH_INDIAN_OCEAN_TERRITORY,
				'SH' => self::SAINT_HELENA,
				'FI' => self::FINLAND,
				'FJ' => self::FIJI,
				'FK' => self::FALKLAND_ISLANDS,
				'FM' => self::MICRONESIA,
				'FO' => self::FAROE_ISLANDS,
				'NI' => self::NICARAGUA,
				'NL' => self::NETHERLANDS,
				'NO' => self::NORWAY,
				'NA' => self::NAMIBIA,
				'VU' => self::VANUATU,
				'NC' => self::NEW_CALEDONIA,
				'NE' => self::NIGER,
				'NF' => self::NORFOLK_ISLAND,
				'NG' => self::NIGERIA,
				'NZ' => self::NEW_ZEALAND,
				'NP' => self::NEPAL,
				'NR' => self::NAURU,
				'NU' => self::NIUE,
				'CK' => self::COOK_ISLANDS,
				'XK' => self::KOSOVO,
				'CI' => self::IVORY_COAST,
				'CH' => self::SWITZERLAND,
				'CO' => self::COLOMBIA,
				'CN' => self::CHINA,
				'CM' => self::CAMEROON,
				'CL' => self::CHILE,
				'CC' => self::COCOS_ISLANDS,
				'CA' => self::CANADA,
				'CG' => self::REPUBLIC_OF_THE_CONGO,
				'CF' => self::CENTRAL_AFRICAN_REPUBLIC,
				'CD' => self::DEMOCRATIC_REPUBLIC_OF_THE_CONGO,
				'CZ' => self::CZECH_REPUBLIC,
				'CY' => self::CYPRUS,
				'CX' => self::CHRISTMAS_ISLAND,
				'CR' => self::COSTA_RICA,
				'CW' => self::CURACAO,
				'CV' => self::CAPE_VERDE,
				'CU' => self::CUBA,
				'SZ' => self::SWAZILAND,
				'SY' => self::SYRIA,
				'SX' => self::SINT_MAARTEN,
				'KG' => self::KYRGYZSTAN,
				'KE' => self::KENYA,
				'SS' => self::SOUTH_SUDAN,
				'SR' => self::SURINAME,
				'KI' => self::KIRIBATI,
				'KH' => self::CAMBODIA,
				'KN' => self::SAINT_KITTS_AND_NEVIS,
				'KM' => self::COMOROS,
				'ST' => self::SAO_TOME_AND_PRINCIPE,
				'SK' => self::SLOVAKIA,
				'KR' => self::SOUTH_KOREA,
				'SI' => self::SLOVENIA,
				'KP' => self::NORTH_KOREA,
				'KW' => self::KUWAIT,
				'SN' => self::SENEGAL,
				'SM' => self::SAN_MARINO,
				'SL' => self::SIERRA_LEONE,
				'SC' => self::SEYCHELLES,
				'KZ' => self::KAZAKHSTAN,
				'KY' => self::CAYMAN_ISLANDS,
				'SG' => self::SINGAPORE,
				'SE' => self::SWEDEN,
				'SD' => self::SUDAN,
				'DO' => self::DOMINICAN_REPUBLIC,
				'DM' => self::DOMINICA,
				'DJ' => self::DJIBOUTI,
				'DK' => self::DENMARK,
				'VG' => self::BRITISH_VIRGIN_ISLANDS,
				'DE' => self::GERMANY,
				'YE' => self::YEMEN,
				'DZ' => self::ALGERIA,
				'US' => self::UNITED_STATES,
				'UY' => self::URUGUAY,
				'YT' => self::MAYOTTE,
				'UM' => self::UNITED_STATES_MINOR_OUTLYING_ISLANDS,
				'LB' => self::LEBANON,
				'LC' => self::SAINT_LUCIA,
				'LA' => self::LAOS,
				'TV' => self::TUVALU,
				'TW' => self::TAIWAN,
				'TT' => self::TRINIDAD_AND_TOBAGO,
				'TR' => self::TURKEY,
				'LK' => self::SRI_LANKA,
				'LI' => self::LIECHTENSTEIN,
				'LV' => self::LATVIA,
				'TO' => self::TONGA,
				'LT' => self::LITHUANIA,
				'LU' => self::LUXEMBOURG,
				'LR' => self::LIBERIA,
				'LS' => self::LESOTHO,
				'TH' => self::THAILAND,
				'TF' => self::FRENCH_SOUTHERN_TERRITORIES,
				'TG' => self::TOGO,
				'TD' => self::CHAD,
				'TC' => self::TURKS_AND_CAICOS_ISLANDS,
				'LY' => self::LIBYA,
				'VA' => self::VATICAN,
				'VC' => self::SAINT_VINCENT_AND_THE_GRENADINES,
				'AE' => self::UNITED_ARAB_EMIRATES,
				'AD' => self::ANDORRA,
				'AG' => self::ANTIGUA_AND_BARBUDA,
				'AF' => self::AFGHANISTAN,
				'AI' => self::ANGUILLA,
				'VI' => self::US_VIRGIN_ISLANDS,
				'IS' => self::ICELAND,
				'IR' => self::IRAN,
				'AM' => self::ARMENIA,
				'AL' => self::ALBANIA,
				'AO' => self::ANGOLA,
				'AQ' => self::ANTARCTICA,
				'AS' => self::AMERICAN_SAMOA,
				'AR' => self::ARGENTINA,
				'AU' => self::AUSTRALIA,
				'AT' => self::AUSTRIA,
				'AW' => self::ARUBA,
				'IN' => self::INDIA,
				'AX' => self::ALAND_ISLANDS,
				'AZ' => self::AZERBAIJAN,
				'IE' => self::IRELAND,
				'ID' => self::INDONESIA,
				'UA' => self::UKRAINE,
				'QA' => self::QATAR,
				'MZ' => self::MOZAMBIQUE,
				default => false
			};
		}		
	}
?>