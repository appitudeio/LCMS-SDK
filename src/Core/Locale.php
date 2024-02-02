<?php
	/**
	 *	Changelog:
	 * 	- 2023-11-10: Added currencies
	 * 	- 2023-11-10: Refactor the whole structure to be based on the Enum(s)
	 * 	- 2024-01-31: Added Extraction from Request with Locale {5}
	 */
	namespace LCMS\Core;

	use LCMS\Core\Request;
	use LCMS\Util\Singleton;

	use \Exception;

	define(__NAMESPACE__ . '\COUNTRY_CODE', 	'code');
	define(__NAMESPACE__ . '\COUNTRY_PHONE', 	'phone');
	define(__NAMESPACE__ . '\COUNTRY_NAME', 	'name');	
	define(__NAMESPACE__ . '\LANGUAGE_CODE', 	'language');
	define(__NAMESPACE__ . '\CURRENCY', 		'currency');
	define(__NAMESPACE__ . '\LOCALE', 			'locale');
	define(__NAMESPACE__ . '\TIMEZONE', 		'timezone');

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
			LOCALE => null,
			TIMEZONE => null
		];
		private bool $is_default = false;

		/**
		 * 	Getters
		 */
		protected function getLanguage(): string | false
		{
			return $this->asArray()[LANGUAGE_CODE] ?? false;
		}

		protected function getLocale(): string | false
		{
			return $this->asArray()[LOCALE] ?? false;
		}

		protected function getTimezone(): string | false
		{
			return $this->asArray()[TIMEZONE] ?? false;
		}

		protected function getCountry(): string | false
		{
			return $this->asArray()[COUNTRY_NAME] ?? false;
		}

		protected function getCountryCode(): string | false
		{
			return $this->asArray()[COUNTRY_CODE] ?? false;
		}
		
		protected function getCurrency(): string | false
		{
			return $this->asArray()[CURRENCY] ?? false;
		}

		protected function asArray(): array
		{
			return array_filter($this->config);
		}		

		protected function isDefault(): bool
		{
			return $this->is_default;
		}

		/**
		 * 	Setters
		 */
		protected function setLanguages(array $_languages): self
		{
			$this->languages = $_languages;

			if(false === $this->isDefault() && in_array($this->config[LANGUAGE_CODE], $this->languages))
			{
				$this->is_default = true;
			}

			return $this;
		}
		
		protected function setLanguage(string $_language, bool $_is_default = false): self
		{
			$this->config[LANGUAGE_CODE] = $_language;
			$this->config[LOCALE] = $_language . "_" . explode("_", $this->config[LOCALE])[1];
			$this->is_default = $_is_default;

			return $this;
		}

		protected function setCountry(string $_country_code): self
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
				LOCALE => $ct->getLocale(),
				TIMEZONE => $ct->getTimezone()
			];

			return $this;
		}

		protected function setCurrency(string $_currency): self
		{
			$this->config[CURRENCY] = $_currency;

			return $this;
		}

		protected function setTimezone(string $_timezone): self
		{
			if(!in_array($_timezone, array_column(Locale::getTimezones(), 1)))
			{
				throw new Exception("Timezone ".$_timezone." not supported");
			}

			$this->config[TIMEZONE] = $_timezone;

			return $this;
		}
		
		/**
		 *	Parse Locale from URLx
		 *	Added 2024-01-31: either /se, /sv-se (locale)
		 */
		protected function extract(Request $request): string | false
		{
			// Test length (2 | 5 + (-_))
			if(!$test_locale = (count($request->segments()) > 0 && (strlen($request->segments()[0]) == 2 || (strlen($request->segments()[0]) == 5 && ($request->segments()[0][2] == "-" || $request->segments()[0][2] == "_")))) ? strtolower($request->segments()[0]) : false)
			{
				return false;
			}

			$locale_parts = explode("-", explode("_", $test_locale)[0])[0];

			if(!in_array($locale_parts[0], $this->languages))
			{
				return false;
			}
			elseif($locale_parts[0] != $this->config[LANGUAGE_CODE] && $this->isDefault() === true)
			{
				$this->is_default = false;
			}

			$this->setLanguage($locale_parts[0]);

			if(isset($locale_parts[1]))
			{
				$this->setCountry($locale_parts[1]);
			}

			return $this->getLanguage();
		}

		/**
		 * 	@return [[ Name, Timezone, UTC Offset ]]
		 */
		public static function getTimezones(): array
		{
			return [
				['Midway Island', 'Pacific/Midway', '-11:00'],
				['Samoa', 'Pacific/Samoa', '-11:00'],
				['Hawaii', 'Pacific/Honolulu', '-10:00'],
				['Alaska', 'US/Alaska', '-09:00'],
				['Pacific Time (US & Canada)', 'America/Los_Angeles', '-08:00'],
				['Tijuana', 'America/Tijuana', '-08:00'],
				['Arizona', 'US/Arizona', '-07:00'],
				['Chihuahua', 'America/Chihuahua', '-07:00'],
				['La Paz', 'America/Chihuahua', '-07:00'],
				['Mazatlan', 'America/Mazatlan', '-07:00'],
				['Mountain Time (US & Canada)', 'US/Mountain', '-07:00'],
				['Central America', 'America/Managua', '-06:00'],
				['Central Time (US & Canada)', 'US/Central', '-06:00'],
				['Guadalajara', 'America/Mexico_City', '-06:00'],
				['Mexico City', 'America/Mexico_City', '-06:00'],
				['Monterrey', 'America/Monterrey', '-06:00'],
				['Saskatchewan', 'Canada/Saskatchewan', '-06:00'],
				['Bogota', 'America/Bogota', '-05:00'],
				['Eastern Time (US & Canada)', 'US/Eastern', '-05:00'],
				['Indiana (East)', 'US/East-Indiana', '-05:00'],
				['Lima', 'America/Lima', '-05:00'],
				['Quito', 'America/Bogota', '-05:00'],
				['Atlantic Time (Canada)', 'Canada/Atlantic', '-04:00'],
				['Caracas', 'America/Caracas', '-04:30'],
				['La Paz', 'America/La_Paz', '-04:00'],
				['Santiago', 'America/Santiago', '-04:00'],
				['Newfoundland', 'Canada/Newfoundland', '-03:30'],
				['Brasilia', 'America/Sao_Paulo', '-03:00'],
				['Buenos Aires', 'America/Argentina/Buenos_Aires', '-03:00'],
				['Georgetown', 'America/Argentina/Buenos_Aires', '-03:00'],
				['Greenland', 'America/Godthab', '-03:00'],
				['Mid-Atlantic', 'America/Noronha', '-02:00'],
				['Azores', 'Atlantic/Azores', '-01:00'],
				['Cape Verde Is.', 'Atlantic/Cape_Verde', '-01:00'],
				['Casablanca', 'Africa/Casablanca', '+00:00'],
				['Edinburgh', 'Europe/London', '+00:00'],
				['Greenwich Mean Time : Dublin', 'Etc/Greenwich', '+00:00'],
				['Lisbon', 'Europe/Lisbon', '+00:00'],
				['London', 'Europe/London', '+00:00'],
				['Monrovia', 'Africa/Monrovia', '+00:00'],
				['UTC', 'UTC', '+00:00'],
				['Amsterdam', 'Europe/Amsterdam', '+01:00'],
				['Belgrade', 'Europe/Belgrade', '+01:00'],
				['Berlin', 'Europe/Berlin', '+01:00'],
				['Bern', 'Europe/Berlin', '+01:00'],
				['Bratislava', 'Europe/Bratislava', '+01:00'],
				['Brussels', 'Europe/Brussels', '+01:00'],
				['Budapest', 'Europe/Budapest', '+01:00'],
				['Copenhagen', 'Europe/Copenhagen', '+01:00'],
				['Ljubljana', 'Europe/Ljubljana', '+01:00'],
				['Madrid', 'Europe/Madrid', '+01:00'],
				['Paris', 'Europe/Paris', '+01:00'],
				['Prague', 'Europe/Prague', '+01:00'],
				['Rome', 'Europe/Rome', '+01:00'],
				['Sarajevo', 'Europe/Sarajevo', '+01:00'],
				['Skopje', 'Europe/Skopje', '+01:00'],
				['Stockholm', 'Europe/Stockholm', '+01:00'],
				['Vienna', 'Europe/Vienna', '+01:00'],
				['Warsaw', 'Europe/Warsaw', '+01:00'],
				['West Central Africa', 'Africa/Lagos', '+01:00'],
				['Zagreb', 'Europe/Zagreb', '+01:00'],
				['Athens', 'Europe/Athens', '+02:00'],
				['Bucharest', 'Europe/Bucharest', '+02:00'],
				['Cairo', 'Africa/Cairo', '+02:00'],
				['Harare', 'Africa/Harare', '+02:00'],
				['Helsinki', 'Europe/Helsinki', '+02:00'],
				['Istanbul', 'Europe/Istanbul', '+02:00'],
				['Jerusalem', 'Asia/Jerusalem', '+02:00'],
				['Kyiv', 'Europe/Helsinki', '+02:00'],
				['Pretoria', 'Africa/Johannesburg', '+02:00'],
				['Riga', 'Europe/Riga', '+02:00'],
				['Sofia', 'Europe/Sofia', '+02:00'],
				['Tallinn', 'Europe/Tallinn', '+02:00'],
				['Vilnius', 'Europe/Vilnius', '+02:00'],
				['Baghdad', 'Asia/Baghdad', '+03:00'],
				['Kuwait', 'Asia/Kuwait', '+03:00'],
				['Minsk', 'Europe/Minsk', '+03:00'],
				['Nairobi', 'Africa/Nairobi', '+03:00'],
				['Riyadh', 'Asia/Riyadh', '+03:00'],
				['Volgograd', 'Europe/Volgograd', '+03:00'],
				['Tehran', 'Asia/Tehran', '+03:30'],
				['Abu Dhabi', 'Asia/Muscat', '+04:00'],
				['Baku', 'Asia/Baku', '+04:00'],
				['Moscow', 'Europe/Moscow', '+04:00'],
				['Muscat', 'Asia/Muscat', '+04:00'],
				['St. Petersburg', 'Europe/Moscow', '+04:00'],
				['Tbilisi', 'Asia/Tbilisi', '+04:00'],
				['Yerevan', 'Asia/Yerevan', '+04:00'],
				['Kabul', 'Asia/Kabul', '+04:30'],
				['Islamabad', 'Asia/Karachi', '+05:00'],
				['Karachi', 'Asia/Karachi', '+05:00'],
				['Tashkent', 'Asia/Tashkent', '+05:00'],
				['Chennai', 'Asia/Calcutta', '+05:30'],
				['Kolkata', 'Asia/Kolkata', '+05:30'],
				['Mumbai', 'Asia/Calcutta', '+05:30'],
				['New Delhi', 'Asia/Calcutta', '+05:30'],
				['Sri Jayawardenepura', 'Asia/Calcutta', '+05:30'],
				['Kathmandu', 'Asia/Katmandu', '+05:45'],
				['Almaty', 'Asia/Almaty', '+06:00'],
				['Astana', 'Asia/Dhaka', '+06:00'],
				['Dhaka', 'Asia/Dhaka', '+06:00'],
				['Ekaterinburg', 'Asia/Yekaterinburg', '+06:00'],
				['Rangoon', 'Asia/Rangoon', '+06:30'],
				['Bangkok', 'Asia/Bangkok', '+07:00'],
				['Hanoi', 'Asia/Bangkok', '+07:00'],
				['Jakarta', 'Asia/Jakarta', '+07:00'],
				['Novosibirsk', 'Asia/Novosibirsk', '+07:00'],
				['Beijing', 'Asia/Hong_Kong', '+08:00'],
				['Chongqing', 'Asia/Chongqing', '+08:00'],
				['Hong Kong', 'Asia/Hong_Kong', '+08:00'],
				['Krasnoyarsk', 'Asia/Krasnoyarsk', '+08:00'],
				['Kuala Lumpur', 'Asia/Kuala_Lumpur', '+08:00'],
				['Perth', 'Australia/Perth', '+08:00'],
				['Singapore', 'Asia/Singapore', '+08:00'],
				['Taipei', 'Asia/Taipei', '+08:00'],
				['Ulaan Bataar', 'Asia/Ulan_Bator', '+08:00'],
				['Urumqi', 'Asia/Urumqi', '+08:00'],
				['Irkutsk', 'Asia/Irkutsk', '+09:00'],
				['Osaka', 'Asia/Tokyo', '+09:00'],
				['Sapporo', 'Asia/Tokyo', '+09:00'],
				['Seoul', 'Asia/Seoul', '+09:00'],
				['Tokyo', 'Asia/Tokyo', '+09:00'],
				['Adelaide', 'Australia/Adelaide', '+09:30'],
				['Darwin', 'Australia/Darwin', '+09:30'],
				['Brisbane', 'Australia/Brisbane', '+10:00'],
				['Canberra', 'Australia/Canberra', '+10:00'],
				['Guam', 'Pacific/Guam', '+10:00'],
				['Hobart', 'Australia/Hobart', '+10:00'],
				['Melbourne', 'Australia/Melbourne', '+10:00'],
				['Port Moresby', 'Pacific/Port_Moresby', '+10:00'],
				['Sydney', 'Australia/Sydney', '+10:00'],
				['Yakutsk', 'Asia/Yakutsk', '+10:00'],
				['Vladivostok', 'Asia/Vladivostok', '+11:00'],
				['Auckland', 'Pacific/Auckland', '+12:00'],
				['Fiji', 'Pacific/Fiji', '+12:00'],
				['International Date Line West', 'Pacific/Kwajalein', '+12:00'],
				['Kamchatka', 'Asia/Kamchatka', '+12:00'],
				['Magadan', 'Asia/Magadan', '+12:00'],
				['Marshall Is.', 'Pacific/Fiji', '+12:00'],
				['New Caledonia', 'Asia/Magadan', '+12:00'],
				['Solomon Is.', 'Asia/Magadan', '+12:00'],
				['Wellington', 'Pacific/Auckland', '+12:00'],
				["Nuku'alofa", 'Pacific/Tongatapu', '+13:00']
			];
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

		public function getTimezone(): string
		{
			return $this->getData(TIMEZONE);
		}
	
		public function getData(string | null $_type = null): string | array
		{
			$country = match($this) 
			{
				self::BANGLADESH => [COUNTRY_NAME => 'Bangladesh', COUNTRY_CODE => 'BD', COUNTRY_PHONE => '880', LANGUAGE_CODE => 'bn', CURRENCY => 'BDT', TIMEZONE => 'Asia/Dhaka'],
				self::BELGIUM => [COUNTRY_NAME => 'Belgium', COUNTRY_CODE => 'BE', COUNTRY_PHONE => '32', LANGUAGE_CODE => 'nl', CURRENCY => 'EUR', TIMEZONE => 'Europe/Brussels'],
				self::BURKINA_FASO => [COUNTRY_NAME => 'Burkina Faso', COUNTRY_CODE => 'BF', COUNTRY_PHONE => '226', LANGUAGE_CODE => 'fr', CURRENCY => 'XOF', TIMEZONE => 'Africa/Ouagadougou'],
				self::BULGARIA => [COUNTRY_NAME => 'Bulgaria', COUNTRY_CODE => 'BG', COUNTRY_PHONE => '359', LANGUAGE_CODE => 'bg', CURRENCY => 'BGN', TIMEZONE => 'Europe/Sofia'],
				self::BOSNIA_AND_HERZEGOVINA => [COUNTRY_NAME => 'Bosnia and Herzegovina', COUNTRY_CODE => 'BA', COUNTRY_PHONE => '387', LANGUAGE_CODE => 'bs', CURRENCY => 'BAM', TIMEZONE => 'Europe/Sarajevo'],
				self::BARBADOS => [COUNTRY_NAME => 'Barbados', COUNTRY_CODE => 'BB', COUNTRY_PHONE => '1-246', LANGUAGE_CODE => 'en', CURRENCY => 'BBD', TIMEZONE => 'America/Barbados'],
				self::WALLIS_AND_FUTUNA => [COUNTRY_NAME => 'Wallis and Futuna', COUNTRY_CODE => 'WF', COUNTRY_PHONE => '681', LANGUAGE_CODE => 'fr', CURRENCY => 'XPF', TIMEZONE => 'Pacific/Wallis'],
				self::SAINT_BARTHELEMY => [COUNTRY_NAME => 'Saint Barthelemy', COUNTRY_CODE => 'BL', COUNTRY_PHONE => '590', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR', TIMEZONE => 'America/St_Barthelemy'],
				self::BERMUDA => [COUNTRY_NAME => 'Bermuda', COUNTRY_CODE => 'BM', COUNTRY_PHONE => '1-441', LANGUAGE_CODE => 'en', CURRENCY => 'BMD', TIMEZONE => 'Atlantic/Bermuda'],
				self::BRUNEI => [COUNTRY_NAME => 'Brunei', COUNTRY_CODE => 'BN', COUNTRY_PHONE => '673', LANGUAGE_CODE => 'ms', CURRENCY => 'BND', TIMEZONE => 'Asia/Brunei'],
				self::BOLIVIA => [COUNTRY_NAME => 'Bolivia', COUNTRY_CODE => 'BO', COUNTRY_PHONE => '591', LANGUAGE_CODE => 'es', CURRENCY => 'BOB', TIMEZONE => 'America/La_Paz'],
				self::BAHRAIN => [COUNTRY_NAME => 'Bahrain', COUNTRY_CODE => 'BH', COUNTRY_PHONE => '973', LANGUAGE_CODE => 'ar', CURRENCY => 'BHD', TIMEZONE => 'Asia/Bahrain'],
				self::BURUNDI => [COUNTRY_NAME => 'Burundi', COUNTRY_CODE => 'BI', COUNTRY_PHONE => '257', LANGUAGE_CODE => 'fr', CURRENCY => 'BIF', TIMEZONE => 'Africa/Bujumbura'],
				self::BENIN => [COUNTRY_NAME => 'Benin', COUNTRY_CODE => 'BJ', COUNTRY_PHONE => '229', LANGUAGE_CODE => 'fr', CURRENCY => 'XOF', TIMEZONE => 'Africa/Porto-Novo'],
				self::BHUTAN => [COUNTRY_NAME => 'Bhutan', COUNTRY_CODE => 'BT', COUNTRY_PHONE => '975', LANGUAGE_CODE => 'dz', CURRENCY => 'BTN', TIMEZONE => 'Asia/Thimphu'],
				self::JAMAICA => [COUNTRY_NAME => 'Jamaica', COUNTRY_CODE => 'JM', COUNTRY_PHONE => '1-876', LANGUAGE_CODE => 'en', CURRENCY => 'JMD', TIMEZONE => 'America/Jamaica'],
				self::BOUVET_ISLAND => [COUNTRY_NAME => 'Bouvet Island', COUNTRY_CODE => 'BV', COUNTRY_PHONE => '', LANGUAGE_CODE => 'en', CURRENCY => 'NOK', TIMEZONE => 'Europe/Oslo'],
				self::BOTSWANA => [COUNTRY_NAME => 'Botswana', COUNTRY_CODE => 'BW', COUNTRY_PHONE => '267', LANGUAGE_CODE => 'en', CURRENCY => 'BWP', TIMEZONE => 'Africa/Gaborone'],
				self::SAMOA => [COUNTRY_NAME => 'Samoa', COUNTRY_CODE => 'WS', COUNTRY_PHONE => '685', LANGUAGE_CODE => 'en', CURRENCY => 'WST', TIMEZONE => 'Pacific/Apia'],
				self::BONAIRE_SAINT_EUSTATIUS_AND_SABA => [COUNTRY_NAME => 'Bonaire, Saint Eustatius and Saba ', COUNTRY_CODE => 'BQ', COUNTRY_PHONE => '599', LANGUAGE_CODE => 'nl', CURRENCY => 'USD', TIMEZONE => 'America/Kralendijk'],
				self::BRAZIL => [COUNTRY_NAME => 'Brazil', COUNTRY_CODE => 'BR', COUNTRY_PHONE => '55', LANGUAGE_CODE => 'pt', CURRENCY => 'BRL', TIMEZONE => 'America/Sao_Paulo'],
				self::BAHAMAS => [COUNTRY_NAME => 'Bahamas', COUNTRY_CODE => 'BS', COUNTRY_PHONE => '1-242', LANGUAGE_CODE => 'en', CURRENCY => 'BSD', TIMEZONE => 'America/Nassau'],
				self::JERSEY => [COUNTRY_NAME => 'Jersey', COUNTRY_CODE => 'JE', COUNTRY_PHONE => '44-1534', LANGUAGE_CODE => 'en', CURRENCY => 'GBP', TIMEZONE => 'Europe/Jersey'],
				self::BELARUS => [COUNTRY_NAME => 'Belarus', COUNTRY_CODE => 'BY', COUNTRY_PHONE => '375', LANGUAGE_CODE => 'be', CURRENCY => 'BYN', TIMEZONE => 'Europe/Minsk'],
				self::BELIZE => [COUNTRY_NAME => 'Belize', COUNTRY_CODE => 'BZ', COUNTRY_PHONE => '501', LANGUAGE_CODE => 'en', CURRENCY => 'BZD', TIMEZONE => 'America/Belize'],
				self::RUSSIA => [COUNTRY_NAME => 'Russia', COUNTRY_CODE => 'RU', COUNTRY_PHONE => '7', LANGUAGE_CODE => 'ru', CURRENCY => 'RUB', TIMEZONE => 'Europe/Moscow'],
				self::RWANDA => [COUNTRY_NAME => 'Rwanda', COUNTRY_CODE => 'RW', COUNTRY_PHONE => '250', LANGUAGE_CODE => 'fr', CURRENCY => 'RWF', TIMEZONE => 'Africa/Kigali'],
				self::SERBIA => [COUNTRY_NAME => 'Serbia', COUNTRY_CODE => 'RS', COUNTRY_PHONE => '381', LANGUAGE_CODE => 'sr', CURRENCY => 'RSD', TIMEZONE => 'Europe/Belgrade'],
				self::EAST_TIMOR => [COUNTRY_NAME => 'East Timor', COUNTRY_CODE => 'TL', COUNTRY_PHONE => '670', LANGUAGE_CODE => 'tet', CURRENCY => 'USD', TIMEZONE => 'Asia/Dili'],
				self::REUNION => [COUNTRY_NAME => 'Reunion', COUNTRY_CODE => 'RE', COUNTRY_PHONE => '262', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR', TIMEZONE => 'Indian/Reunion'],
				self::TURKMENISTAN => [COUNTRY_NAME => 'Turkmenistan', COUNTRY_CODE => 'TM', COUNTRY_PHONE => '993', LANGUAGE_CODE => 'tk', CURRENCY => 'TMT', TIMEZONE => 'Asia/Ashgabat'],
				self::TAJIKISTAN => [COUNTRY_NAME => 'Tajikistan', COUNTRY_CODE => 'TJ', COUNTRY_PHONE => '992', LANGUAGE_CODE => 'tg', CURRENCY => 'TJS', TIMEZONE => 'Asia/Dushanbe'],
				self::ROMANIA => [COUNTRY_NAME => 'Romania', COUNTRY_CODE => 'RO', COUNTRY_PHONE => '40', LANGUAGE_CODE => 'ro', CURRENCY => 'RON', TIMEZONE => 'Europe/Bucharest'],
				self::TOKELAU => [COUNTRY_NAME => 'Tokelau', COUNTRY_CODE => 'TK', COUNTRY_PHONE => '690', LANGUAGE_CODE => 'tk', CURRENCY => 'NZD', TIMEZONE => 'Pacific/Fakaofo'],
				self::GUINEA_BISSAU => [COUNTRY_NAME => 'Guinea-Bissau', COUNTRY_CODE => 'GW', COUNTRY_PHONE => '245', LANGUAGE_CODE => 'pt', CURRENCY => 'XOF', TIMEZONE => 'Africa/Bissau'],
				self::GUAM => [COUNTRY_NAME => 'Guam', COUNTRY_CODE => 'GU', COUNTRY_PHONE => '1-671', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Pacific/Guam'],
				self::GUATEMALA => [COUNTRY_NAME => 'Guatemala', COUNTRY_CODE => 'GT', COUNTRY_PHONE => '502', LANGUAGE_CODE => 'es', CURRENCY => 'GTQ', TIMEZONE => 'America/Guatemala'],
				self::SOUTH_GEORGIA_AND_THE_SOUTH_SANDWICH_ISLANDS => [COUNTRY_NAME => 'South Georgia and the South Sandwich Islands', COUNTRY_CODE => 'GS', COUNTRY_PHONE => '', LANGUAGE_CODE => 'en', CURRENCY => 'GBP', TIMEZONE => 'Atlantic/South_Georgia'],
				self::GREECE => [COUNTRY_NAME => 'Greece', COUNTRY_CODE => 'GR', COUNTRY_PHONE => '30', LANGUAGE_CODE => 'el', CURRENCY => 'USD', TIMEZONE => 'Europe/Athens'],
				self::EQUATORIAL_GUINEA => [COUNTRY_NAME => 'Equatorial Guinea', COUNTRY_CODE => 'GQ', COUNTRY_PHONE => '240', LANGUAGE_CODE => 'es', CURRENCY => 'EUR', TIMEZONE => 'Africa/Malabo'],
				self::GUADELOUPE => [COUNTRY_NAME => 'Guadeloupe', COUNTRY_CODE => 'GP', COUNTRY_PHONE => '590', LANGUAGE_CODE => 'fr', CURRENCY => 'USD', TIMEZONE => 'America/Guadeloupe'],
				self::JAPAN => [COUNTRY_NAME => 'Japan', COUNTRY_CODE => 'JP', COUNTRY_PHONE => '81', LANGUAGE_CODE => 'ja', CURRENCY => 'JPY', TIMEZONE => 'Asia/Tokyo'],
				self::GUYANA => [COUNTRY_NAME => 'Guyana', COUNTRY_CODE => 'GY', COUNTRY_PHONE => '592', LANGUAGE_CODE => 'en', CURRENCY => 'GYD', TIMEZONE => 'America/Guyana'],
				self::GUERNSEY => [COUNTRY_NAME => 'Guernsey', COUNTRY_CODE => 'GG', COUNTRY_PHONE => '44-1481', LANGUAGE_CODE => 'en', CURRENCY => 'GBP', TIMEZONE => 'Europe/Guernsey'],
				self::FRENCH_GUIANA => [COUNTRY_NAME => 'French Guiana', COUNTRY_CODE => 'GF', COUNTRY_PHONE => '594', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR', TIMEZONE => 'America/Cayenne'],
				self::GEORGIA => [COUNTRY_NAME => 'Georgia', COUNTRY_CODE => 'GE', COUNTRY_PHONE => '995', LANGUAGE_CODE => 'ka', CURRENCY => 'GEL', TIMEZONE => 'Asia/Tbilisi'],
				self::GRENADA => [COUNTRY_NAME => 'Grenada', COUNTRY_CODE => 'GD', COUNTRY_PHONE => '1-473', LANGUAGE_CODE => 'en', CURRENCY => 'XCD', TIMEZONE => 'America/Grenada'],
				self::UNITED_KINGDOM => [COUNTRY_NAME => 'United Kingdom', COUNTRY_CODE => 'GB', COUNTRY_PHONE => '44', LANGUAGE_CODE => 'en', CURRENCY => 'GBP', TIMEZONE => 'Europe/London'],
				self::GABON => [COUNTRY_NAME => 'Gabon', COUNTRY_CODE => 'GA', COUNTRY_PHONE => '241', LANGUAGE_CODE => 'fr', CURRENCY => 'XAF', TIMEZONE => 'Africa/Libreville'],
				self::EL_SALVADOR => [COUNTRY_NAME => 'El Salvador', COUNTRY_CODE => 'SV', COUNTRY_PHONE => '503', LANGUAGE_CODE => 'es', CURRENCY => 'USD', TIMEZONE => 'America/El_Salvador'],
				self::GUINEA => [COUNTRY_NAME => 'Guinea', COUNTRY_CODE => 'GN', COUNTRY_PHONE => '224', LANGUAGE_CODE => 'fr', CURRENCY => 'GNF', TIMEZONE => 'Africa/Conakry'],
				self::GAMBIA => [COUNTRY_NAME => 'Gambia', COUNTRY_CODE => 'GM', COUNTRY_PHONE => '220', LANGUAGE_CODE => 'en', CURRENCY => 'GMD', TIMEZONE => 'Africa/Banjul'],
				self::GREENLAND => [COUNTRY_NAME => 'Greenland', COUNTRY_CODE => 'GL', COUNTRY_PHONE => '299', LANGUAGE_CODE => 'kl', CURRENCY => 'DKK', TIMEZONE => 'America/Godthab'],
				self::GIBRALTAR => [COUNTRY_NAME => 'Gibraltar', COUNTRY_CODE => 'GI', COUNTRY_PHONE => '350', LANGUAGE_CODE => 'en', CURRENCY => 'GIP', TIMEZONE => 'Europe/Gibraltar'],
				self::GHANA => [COUNTRY_NAME => 'Ghana', COUNTRY_CODE => 'GH', COUNTRY_PHONE => '233', LANGUAGE_CODE => 'en', CURRENCY => 'GHS', TIMEZONE => 'Africa/Accra'],
				self::OMAN => [COUNTRY_NAME => 'Oman', COUNTRY_CODE => 'OM', COUNTRY_PHONE => '968', LANGUAGE_CODE => 'ar', CURRENCY => 'OMR', TIMEZONE => 'Asia/Muscat'],
				self::TUNISIA => [COUNTRY_NAME => 'Tunisia', COUNTRY_CODE => 'TN', COUNTRY_PHONE => '216', LANGUAGE_CODE => 'ar', CURRENCY => 'TND', TIMEZONE => 'Africa/Tunis'],
				self::JORDAN => [COUNTRY_NAME => 'Jordan', COUNTRY_CODE => 'JO', COUNTRY_PHONE => '962', LANGUAGE_CODE => 'ar', CURRENCY => 'JOD', TIMEZONE => 'Asia/Amman'],
				self::CROATIA => [COUNTRY_NAME => 'Croatia', COUNTRY_CODE => 'HR', COUNTRY_PHONE => '385', LANGUAGE_CODE => 'hr', CURRENCY => 'HRK', TIMEZONE => 'Europe/Zagreb'],
				self::HAITI => [COUNTRY_NAME => 'Haiti', COUNTRY_CODE => 'HT', COUNTRY_PHONE => '509', LANGUAGE_CODE => 'fr', CURRENCY => 'HTG', TIMEZONE => 'America/Port-au-Prince'],
				self::HUNGARY => [COUNTRY_NAME => 'Hungary', COUNTRY_CODE => 'HU', COUNTRY_PHONE => '36', LANGUAGE_CODE => 'hu', CURRENCY => 'HUF', TIMEZONE => 'Europe/Budapest'],
				self::HONG_KONG => [COUNTRY_NAME => 'Hong Kong', COUNTRY_CODE => 'HK', COUNTRY_PHONE => '852', LANGUAGE_CODE => 'en', CURRENCY => 'HKD', TIMEZONE => 'Asia/Hong_Kong'],
				self::HONDURAS => [COUNTRY_NAME => 'Honduras', COUNTRY_CODE => 'HN', COUNTRY_PHONE => '504', LANGUAGE_CODE => 'es', CURRENCY => 'HNL', TIMEZONE => 'America/Tegucigalpa'],
				self::HEARD_ISLAND_AND_MCDONALD_ISLANDS => [COUNTRY_NAME => 'Heard Island and McDonald Islands', COUNTRY_CODE => 'HM', COUNTRY_PHONE => ' ', LANGUAGE_CODE => 'en', CURRENCY => 'AUD', TIMEZONE => 'Indian/Kerguelen'],
				self::VENEZUELA => [COUNTRY_NAME => 'Venezuela', COUNTRY_CODE => 'VE', COUNTRY_PHONE => '58', LANGUAGE_CODE => 'es', CURRENCY => 'VES', TIMEZONE => 'America/Caracas'],
				self::PUERTO_RICO => [COUNTRY_NAME => 'Puerto Rico', COUNTRY_CODE => 'PR', COUNTRY_PHONE => '1-787 and 1-939', LANGUAGE_CODE => 'es', CURRENCY => 'USD', TIMEZONE => 'America/Puerto_Rico'],
				self::PALESTINIAN_TERRITORY => [COUNTRY_NAME => 'Palestinian Territory', COUNTRY_CODE => 'PS', COUNTRY_PHONE => '970', LANGUAGE_CODE => 'ar', CURRENCY => 'ILS', TIMEZONE => 'Asia/Gaza'],
				self::PALAU => [COUNTRY_NAME => 'Palau', COUNTRY_CODE => 'PW', COUNTRY_PHONE => '680', LANGUAGE_CODE => 'pa', CURRENCY => 'USD', TIMEZONE => 'Pacific/Palau'],
				self::PORTUGAL => [COUNTRY_NAME => 'Portugal', COUNTRY_CODE => 'PT', COUNTRY_PHONE => '351', LANGUAGE_CODE => 'ps', CURRENCY => 'EUR', TIMEZONE => 'Europe/Lisbon'],
				self::SVALBARD_AND_JAN_MAYEN => [COUNTRY_NAME => 'Svalbard and Jan Mayen', COUNTRY_CODE => 'SJ', COUNTRY_PHONE => '47', LANGUAGE_CODE => 'no', CURRENCY => 'NOK', TIMEZONE => 'Arctic/Longyearbyen'],
				self::PARAGUAY => [COUNTRY_NAME => 'Paraguay', COUNTRY_CODE => 'PY', COUNTRY_PHONE => '595', LANGUAGE_CODE => 'es', CURRENCY => 'PYG', TIMEZONE => 'America/Asuncion'],
				self::IRAQ => [COUNTRY_NAME => 'Iraq', COUNTRY_CODE => 'IQ', COUNTRY_PHONE => '964', LANGUAGE_CODE => 'ar', CURRENCY => 'IQD', TIMEZONE => 'Asia/Baghdad'],
				self::PANAMA => [COUNTRY_NAME => 'Panama', COUNTRY_CODE => 'PA', COUNTRY_PHONE => '507', LANGUAGE_CODE => 'es', CURRENCY => 'PAB', TIMEZONE => 'America/Panama'],
				self::FRENCH_POLYNESIA => [COUNTRY_NAME => 'French Polynesia', COUNTRY_CODE => 'PF', COUNTRY_PHONE => '689', LANGUAGE_CODE => 'fr', CURRENCY => 'XPF', TIMEZONE => 'Pacific/Tahiti'],
				self::PAPUA_NEW_GUINEA => [COUNTRY_NAME => 'Papua New Guinea', COUNTRY_CODE => 'PG', COUNTRY_PHONE => '675', LANGUAGE_CODE => 'en', CURRENCY => 'PGK', TIMEZONE => 'Pacific/Port_Moresby'],
				self::PERU => [COUNTRY_NAME => 'Peru', COUNTRY_CODE => 'PE', COUNTRY_PHONE => '51', LANGUAGE_CODE => 'es', CURRENCY => 'USD', TIMEZONE => 'America/Lima'],
				self::PAKISTAN => [COUNTRY_NAME => 'Pakistan', COUNTRY_CODE => 'PK', COUNTRY_PHONE => '92', LANGUAGE_CODE => 'ur', CURRENCY => 'USD', TIMEZONE => 'Asia/Karachi'],
				self::PHILIPPINES => [COUNTRY_NAME => 'Philippines', COUNTRY_CODE => 'PH', COUNTRY_PHONE => '63', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Asia/Manila'],
				self::PITCAIRN => [COUNTRY_NAME => 'Pitcairn', COUNTRY_CODE => 'PN', COUNTRY_PHONE => '870', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Pacific/Pitcairn'],
				self::POLAND => [COUNTRY_NAME => 'Poland', COUNTRY_CODE => 'PL', COUNTRY_PHONE => '48', LANGUAGE_CODE => 'pl', CURRENCY => 'USD', TIMEZONE => 'Europe/Warsaw'],
				self::SAINT_PIERRE_AND_MIQUELON => [COUNTRY_NAME => 'Saint Pierre and Miquelon', COUNTRY_CODE => 'PM', COUNTRY_PHONE => '508', LANGUAGE_CODE => 'fr', CURRENCY => 'USD', TIMEZONE => 'America/Miquelon'],
				self::ZAMBIA => [COUNTRY_NAME => 'Zambia', COUNTRY_CODE => 'ZM', COUNTRY_PHONE => '260', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Africa/Lusaka'],
				self::WESTERN_SAHARA => [COUNTRY_NAME => 'Western Sahara', COUNTRY_CODE => 'EH', COUNTRY_PHONE => '212', LANGUAGE_CODE => 'ar', CURRENCY => 'USD', TIMEZONE => 'Africa/El_Aaiun'],
				self::ESTONIA => [COUNTRY_NAME => 'Estonia', COUNTRY_CODE => 'EE', COUNTRY_PHONE => '372', LANGUAGE_CODE => 'et', CURRENCY => 'USD', TIMEZONE => 'Europe/Tallinn'],
				self::EGYPT => [COUNTRY_NAME => 'Egypt', COUNTRY_CODE => 'EG', COUNTRY_PHONE => '20', LANGUAGE_CODE => 'ar', CURRENCY => 'USD', TIMEZONE => 'Africa/Cairo'],
				self::SOUTH_AFRICA => [COUNTRY_NAME => 'South Africa', COUNTRY_CODE => 'ZA', COUNTRY_PHONE => '27', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Africa/Johannesburg'],
				self::ECUADOR => [COUNTRY_NAME => 'Ecuador', COUNTRY_CODE => 'EC', COUNTRY_PHONE => '593', LANGUAGE_CODE => 'es', CURRENCY => 'USD', TIMEZONE => 'America/Guayaquil'],
				self::ITALY => [COUNTRY_NAME => 'Italy', COUNTRY_CODE => 'IT', COUNTRY_PHONE => '39', LANGUAGE_CODE => 'it', CURRENCY => 'USD', TIMEZONE => 'Europe/Rome'],
				self::VIETNAM => [COUNTRY_NAME => 'Vietnam', COUNTRY_CODE => 'VN', COUNTRY_PHONE => '84', LANGUAGE_CODE => 'vi', CURRENCY => 'USD', TIMEZONE => 'Asia/Ho_Chi_Minh'],
				self::SOLOMON_ISLANDS => [COUNTRY_NAME => 'Solomon Islands', COUNTRY_CODE => 'SB', COUNTRY_PHONE => '677', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Pacific/Guadalcanal'],
				self::ETHIOPIA => [COUNTRY_NAME => 'Ethiopia', COUNTRY_CODE => 'ET', COUNTRY_PHONE => '251', LANGUAGE_CODE => 'am', CURRENCY => 'USD', TIMEZONE => 'Africa/Addis_Ababa'],
				self::SOMALIA => [COUNTRY_NAME => 'Somalia', COUNTRY_CODE => 'SO', COUNTRY_PHONE => '252', LANGUAGE_CODE => 'so', CURRENCY => 'USD', TIMEZONE => 'Africa/Mogadishu'],
				self::ZIMBABWE => [COUNTRY_NAME => 'Zimbabwe', COUNTRY_CODE => 'ZW', COUNTRY_PHONE => '263', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Africa/Harare'],
				self::SAUDI_ARABIA => [COUNTRY_NAME => 'Saudi Arabia', COUNTRY_CODE => 'SA', COUNTRY_PHONE => '966', LANGUAGE_CODE => 'ar', CURRENCY => 'USD', TIMEZONE => 'Asia/Riyadh'],
				self::SPAIN => [COUNTRY_NAME => 'Spain', COUNTRY_CODE => 'ES', COUNTRY_PHONE => '34', LANGUAGE_CODE => 'es', CURRENCY => 'USD', TIMEZONE => 'Europe/Madrid'],
				self::ERITREA => [COUNTRY_NAME => 'Eritrea', COUNTRY_CODE => 'ER', COUNTRY_PHONE => '291', LANGUAGE_CODE => 'ti', CURRENCY => 'USD', TIMEZONE => 'Africa/Asmara'],
				self::MONTENEGRO => [COUNTRY_NAME => 'Montenegro', COUNTRY_CODE => 'ME', COUNTRY_PHONE => '382', LANGUAGE_CODE => 'sr', CURRENCY => 'USD', TIMEZONE => 'Europe/Podgorica'],
				self::MOLDOVA => [COUNTRY_NAME => 'Moldova', COUNTRY_CODE => 'MD', COUNTRY_PHONE => '373', LANGUAGE_CODE => 'ro', CURRENCY => 'USD', TIMEZONE => 'Europe/Chisinau'],
				self::MADAGASCAR => [COUNTRY_NAME => 'Madagascar', COUNTRY_CODE => 'MG', COUNTRY_PHONE => '261', LANGUAGE_CODE => 'fr', CURRENCY => 'USD', TIMEZONE => 'Indian/Antananarivo'],
				self::SAINT_MARTIN => [COUNTRY_NAME => 'Saint Martin', COUNTRY_CODE => 'MF', COUNTRY_PHONE => '590', LANGUAGE_CODE => 'fr', CURRENCY => 'USD', TIMEZONE => 'America/Marigot'],
				self::MOROCCO => [COUNTRY_NAME => 'Morocco', COUNTRY_CODE => 'MA', COUNTRY_PHONE => '212', LANGUAGE_CODE => 'ar', CURRENCY => 'MAD', TIMEZONE => 'Africa/Casablanca'],
				self::MONACO => [COUNTRY_NAME => 'Monaco', COUNTRY_CODE => 'MC', COUNTRY_PHONE => '377', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR', TIMEZONE => 'Europe/Monaco'],
				self::UZBEKISTAN => [COUNTRY_NAME => 'Uzbekistan', COUNTRY_CODE => 'UZ', COUNTRY_PHONE => '998', LANGUAGE_CODE => 'uz', CURRENCY => 'USD', TIMEZONE => 'Asia/Samarkand'],
				self::MYANMAR => [COUNTRY_NAME => 'Myanmar', COUNTRY_CODE => 'MM', COUNTRY_PHONE => '95', LANGUAGE_CODE => 'my', CURRENCY => 'USD', TIMEZONE => 'Asia/Yangon'],
				self::MALI => [COUNTRY_NAME => 'Mali', COUNTRY_CODE => 'ML', COUNTRY_PHONE => '223', LANGUAGE_CODE => 'fr', CURRENCY => 'USD', TIMEZONE => 'Africa/Bamako'],
				self::MACAO => [COUNTRY_NAME => 'Macao', COUNTRY_CODE => 'MO', COUNTRY_PHONE => '853', LANGUAGE_CODE => 'pt', CURRENCY => 'USD', TIMEZONE => 'Asia/Macau'],
				self::MONGOLIA => [COUNTRY_NAME => 'Mongolia', COUNTRY_CODE => 'MN', COUNTRY_PHONE => '976', LANGUAGE_CODE => 'mn', CURRENCY => 'USD', TIMEZONE => 'Asia/Ulaanbaatar'],
				self::MARSHALL_ISLANDS => [COUNTRY_NAME => 'Marshall Islands', COUNTRY_CODE => 'MH', COUNTRY_PHONE => '692', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Pacific/Majuro'],
				self::MACEDONIA => [COUNTRY_NAME => 'Macedonia', COUNTRY_CODE => 'MK', COUNTRY_PHONE => '389', LANGUAGE_CODE => 'mk', CURRENCY => 'USD', TIMEZONE => 'Europe/Skopje'],
				self::MAURITIUS => [COUNTRY_NAME => 'Mauritius', COUNTRY_CODE => 'MU', COUNTRY_PHONE => '230', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Indian/Mauritius'],
				self::MALTA => [COUNTRY_NAME => 'Malta', COUNTRY_CODE => 'MT', COUNTRY_PHONE => '356', LANGUAGE_CODE => 'mt', CURRENCY => 'USD', TIMEZONE => 'Europe/Malta'],
				self::MALAWI => [COUNTRY_NAME => 'Malawi', COUNTRY_CODE => 'MW', COUNTRY_PHONE => '265', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Africa/Blantyre'],
				self::MALDIVES => [COUNTRY_NAME => 'Maldives', COUNTRY_CODE => 'MV', COUNTRY_PHONE => '960', LANGUAGE_CODE => 'dv', CURRENCY => 'USD', TIMEZONE => 'Indian/Maldives'],
				self::MARTINIQUE => [COUNTRY_NAME => 'Martinique', COUNTRY_CODE => 'MQ', COUNTRY_PHONE => '596', LANGUAGE_CODE => 'fr', CURRENCY => 'USD', TIMEZONE => 'America/Martinique'],
				self::NORTHERN_MARIANA_ISLANDS => [COUNTRY_NAME => 'Northern Mariana Islands', COUNTRY_CODE => 'MP', COUNTRY_PHONE => '1-670', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Pacific/Saipan'],
				self::MONTSERRAT => [COUNTRY_NAME => 'Montserrat', COUNTRY_CODE => 'MS', COUNTRY_PHONE => '1-664', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'America/Montserrat'],
				self::MAURITANIA => [COUNTRY_NAME => 'Mauritania', COUNTRY_CODE => 'MR', COUNTRY_PHONE => '222', LANGUAGE_CODE => 'ar', CURRENCY => 'USD', TIMEZONE => 'Africa/Nouakchott'],
				self::ISLE_OF_MAN => [COUNTRY_NAME => 'Isle of Man', COUNTRY_CODE => 'IM', COUNTRY_PHONE => '44-1624', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Europe/Isle_of_Man'],
				self::UGANDA => [COUNTRY_NAME => 'Uganda', COUNTRY_CODE => 'UG', COUNTRY_PHONE => '256', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Africa/Kampala'],
				self::TANZANIA => [COUNTRY_NAME => 'Tanzania', COUNTRY_CODE => 'TZ', COUNTRY_PHONE => '255', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Africa/Dar_es_Salaam'],
				self::MALAYSIA => [COUNTRY_NAME => 'Malaysia', COUNTRY_CODE => 'MY', COUNTRY_PHONE => '60', LANGUAGE_CODE => 'ms', CURRENCY => 'MYR', TIMEZONE => 'Asia/Kuala_Lumpur'],
				self::MEXICO => [COUNTRY_NAME => 'Mexico', COUNTRY_CODE => 'MX', COUNTRY_PHONE => '52', LANGUAGE_CODE => 'es', CURRENCY => 'MXN', TIMEZONE => 'America/Mexico_City'],
				self::ISRAEL => [COUNTRY_NAME => 'Israel', COUNTRY_CODE => 'IL', COUNTRY_PHONE => '972', LANGUAGE_CODE => 'he', CURRENCY => 'ILS', TIMEZONE => 'Asia/Jerusalem'],
				self::FRANCE => [COUNTRY_NAME => 'France', COUNTRY_CODE => 'FR', COUNTRY_PHONE => '33', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR', TIMEZONE => 'Europe/Paris'],
				self::BRITISH_INDIAN_OCEAN_TERRITORY => [COUNTRY_NAME => 'British Indian Ocean Territory', COUNTRY_CODE => 'IO', COUNTRY_PHONE => '246', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Indian/Chagos'],
				self::SAINT_HELENA => [COUNTRY_NAME => 'Saint Helena', COUNTRY_CODE => 'SH', COUNTRY_PHONE => '290', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Africa/Abidjan'],
				self::FINLAND => [COUNTRY_NAME => 'Finland', COUNTRY_CODE => 'FI', COUNTRY_PHONE => '358', LANGUAGE_CODE => 'fi', CURRENCY => 'EUR', TIMEZONE => 'Europe/Helsinki'],
				self::FIJI => [COUNTRY_NAME => 'Fiji', COUNTRY_CODE => 'FJ', COUNTRY_PHONE => '679', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Pacific/Fiji'],
				self::FALKLAND_ISLANDS => [COUNTRY_NAME => 'Falkland Islands', COUNTRY_CODE => 'FK', COUNTRY_PHONE => '500', LANGUAGE_CODE => 'en', CURRENCY => 'FKP', TIMEZONE => 'Atlantic/Stanley'],
				self::MICRONESIA => [COUNTRY_NAME => 'Micronesia', COUNTRY_CODE => 'FM', COUNTRY_PHONE => '691', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Pacific/Chuuk'],
				self::FAROE_ISLANDS => [COUNTRY_NAME => 'Faroe Islands', COUNTRY_CODE => 'FO', COUNTRY_PHONE => '298', LANGUAGE_CODE => 'fo', CURRENCY => 'DKK', TIMEZONE => 'Atlantic/Faroe'],
				self::NICARAGUA => [COUNTRY_NAME => 'Nicaragua', COUNTRY_CODE => 'NI', COUNTRY_PHONE => '505', LANGUAGE_CODE => 'es', CURRENCY => 'USD', TIMEZONE => 'America/Managua'],
				self::NETHERLANDS => [COUNTRY_NAME => 'Netherlands', COUNTRY_CODE => 'NL', COUNTRY_PHONE => '31', LANGUAGE_CODE => 'nl', CURRENCY => 'EUR', TIMEZONE => 'Europe/Amsterdam'],
				self::NORWAY => [COUNTRY_NAME => 'Norway', COUNTRY_CODE => 'NO', COUNTRY_PHONE => '47', LANGUAGE_CODE => 'no', CURRENCY => 'NOK', TIMEZONE => 'Europe/Oslo'],
				self::NAMIBIA => [COUNTRY_NAME => 'Namibia', COUNTRY_CODE => 'NA', COUNTRY_PHONE => '264', LANGUAGE_CODE => 'en', CURRENCY => 'NAD', TIMEZONE => 'Africa/Windhoek'],
				self::VANUATU => [COUNTRY_NAME => 'Vanuatu', COUNTRY_CODE => 'VU', COUNTRY_PHONE => '678', LANGUAGE_CODE => 'en', CURRENCY => 'VUV', TIMEZONE => 'Pacific/Efate'],
				self::NEW_CALEDONIA => [COUNTRY_NAME => 'New Caledonia', COUNTRY_CODE => 'NC', COUNTRY_PHONE => '687', LANGUAGE_CODE => 'fr', CURRENCY => 'XPF', TIMEZONE => 'Pacific/Noumea'],
				self::NIGER => [COUNTRY_NAME => 'Niger', COUNTRY_CODE => 'NE', COUNTRY_PHONE => '227', LANGUAGE_CODE => 'fr', CURRENCY => 'XOF', TIMEZONE => 'Africa/Niamey'],
				self::NORFOLK_ISLAND => [COUNTRY_NAME => 'Norfolk Island', COUNTRY_CODE => 'NF', COUNTRY_PHONE => '672', LANGUAGE_CODE => 'en', CURRENCY => 'AUD', TIMEZONE => 'Pacific/Norfolk'],
				self::NIGERIA => [COUNTRY_NAME => 'Nigeria', COUNTRY_CODE => 'NG', COUNTRY_PHONE => '234', LANGUAGE_CODE => 'en', CURRENCY => 'NGN', TIMEZONE => 'Africa/Lagos'],
				self::NEW_ZEALAND => [COUNTRY_NAME => 'New Zealand', COUNTRY_CODE => 'NZ', COUNTRY_PHONE => '64', LANGUAGE_CODE => 'en', CURRENCY => 'NZD', TIMEZONE => 'Pacific/Auckland'],
				self::NEPAL => [COUNTRY_NAME => 'Nepal', COUNTRY_CODE => 'NP', COUNTRY_PHONE => '977', LANGUAGE_CODE => 'ne', CURRENCY => 'NPR', TIMEZONE => 'Asia/Kathmandu'],
				self::NAURU => [COUNTRY_NAME => 'Nauru', COUNTRY_CODE => 'NR', COUNTRY_PHONE => '674', LANGUAGE_CODE => 'na', CURRENCY => 'AUD', TIMEZONE => 'Pacific/Nauru'],
				self::NIUE => [COUNTRY_NAME => 'Niue', COUNTRY_CODE => 'NU', COUNTRY_PHONE => '683', LANGUAGE_CODE => 'niu', CURRENCY => 'NZD', TIMEZONE => 'Pacific/Niue'],
				self::COOK_ISLANDS => [COUNTRY_NAME => 'Cook Islands', COUNTRY_CODE => 'CK', COUNTRY_PHONE => '682', LANGUAGE_CODE => 'en', CURRENCY => 'NZD', TIMEZONE => 'Pacific/Rarotonga'],
				self::KOSOVO => [COUNTRY_NAME => 'Kosovo', COUNTRY_CODE => 'XK', COUNTRY_PHONE => '', LANGUAGE_CODE => 'sq', CURRENCY => 'EUR', TIMEZONE => 'Europe/Belgrade'],
				self::IVORY_COAST => [COUNTRY_NAME => 'Ivory Coast', COUNTRY_CODE => 'CI', COUNTRY_PHONE => '225', LANGUAGE_CODE => 'fr', CURRENCY => 'XOF', TIMEZONE => 'Africa/Abidjan'],
				self::SWITZERLAND => [COUNTRY_NAME => 'Switzerland', COUNTRY_CODE => 'CH', COUNTRY_PHONE => '41', LANGUAGE_CODE => 'de', CURRENCY => 'CHF', TIMEZONE => 'Europe/Zurich'],
				self::COLOMBIA => [COUNTRY_NAME => 'Colombia', COUNTRY_CODE => 'CO', COUNTRY_PHONE => '57', LANGUAGE_CODE => 'es', CURRENCY => 'COP', TIMEZONE => 'America/Bogota'],
				self::CHINA => [COUNTRY_NAME => 'China', COUNTRY_CODE => 'CN', COUNTRY_PHONE => '86', LANGUAGE_CODE => 'zh', CURRENCY => 'CNY', TIMEZONE => 'Asia/Shanghai'],
				self::CAMEROON => [COUNTRY_NAME => 'Cameroon', COUNTRY_CODE => 'CM', COUNTRY_PHONE => '237', LANGUAGE_CODE => 'fr', CURRENCY => 'XAF', TIMEZONE => 'Africa/Douala'],
				self::CHILE => [COUNTRY_NAME => 'Chile', COUNTRY_CODE => 'CL', COUNTRY_PHONE => '56', LANGUAGE_CODE => 'es', CURRENCY => 'CLP', TIMEZONE => 'America/Santiago'],
				self::COCOS_ISLANDS => [COUNTRY_NAME => 'Cocos Islands', COUNTRY_CODE => 'CC', COUNTRY_PHONE => '61', LANGUAGE_CODE => 'en', CURRENCY => 'AUD', TIMEZONE => 'Indian/Cocos'],
				self::CANADA => [COUNTRY_NAME => 'Canada', COUNTRY_CODE => 'CA', COUNTRY_PHONE => '1', LANGUAGE_CODE => 'en', CURRENCY => 'CAD', TIMEZONE => 'America/Toronto'],
				self::REPUBLIC_OF_THE_CONGO => [COUNTRY_NAME => 'Republic of the Congo', COUNTRY_CODE => 'CG', COUNTRY_PHONE => '242', LANGUAGE_CODE => 'fr', CURRENCY => 'XAF', TIMEZONE => 'Africa/Brazzaville'],
				self::CENTRAL_AFRICAN_REPUBLIC => [COUNTRY_NAME => 'Central African Republic', COUNTRY_CODE => 'CF', COUNTRY_PHONE => '236', LANGUAGE_CODE => 'fr', CURRENCY => 'XAF', TIMEZONE => 'Africa/Bangui'],
				self::DEMOCRATIC_REPUBLIC_OF_THE_CONGO => [COUNTRY_NAME => 'Democratic Republic of the Congo', COUNTRY_CODE => 'CD', COUNTRY_PHONE => '243', LANGUAGE_CODE => 'fr', CURRENCY => 'CDF', TIMEZONE => 'Africa/Kinshasa'],
				self::CZECH_REPUBLIC => [COUNTRY_NAME => 'Czech Republic', COUNTRY_CODE => 'CZ', COUNTRY_PHONE => '420', LANGUAGE_CODE => 'cs', CURRENCY => 'CZK', TIMEZONE => 'Europe/Prague'],
				self::CYPRUS => [COUNTRY_NAME => 'Cyprus', COUNTRY_CODE => 'CY', COUNTRY_PHONE => '357', LANGUAGE_CODE => 'el', CURRENCY => 'EUR', TIMEZONE => 'Asia/Nicosia'],
				self::CHRISTMAS_ISLAND => [COUNTRY_NAME => 'Christmas Island', COUNTRY_CODE => 'CX', COUNTRY_PHONE => '61', LANGUAGE_CODE => 'en', CURRENCY => 'AUD', TIMEZONE => 'Indian/Christmas'],
				self::COSTA_RICA => [COUNTRY_NAME => 'Costa Rica', COUNTRY_CODE => 'CR', COUNTRY_PHONE => '506', LANGUAGE_CODE => 'es', CURRENCY => 'CRC', TIMEZONE => 'America/Costa_Rica'],
				self::CURACAO => [COUNTRY_NAME => 'Curacao', COUNTRY_CODE => 'CW', COUNTRY_PHONE => '599', LANGUAGE_CODE => 'nl', CURRENCY => 'ANG', TIMEZONE => 'America/Curacao'],
				self::CAPE_VERDE => [COUNTRY_NAME => 'Cape Verde', COUNTRY_CODE => 'CV', COUNTRY_PHONE => '238', LANGUAGE_CODE => 'pt', CURRENCY => 'CVE', TIMEZONE => 'Atlantic/Cape_Verde'],
				self::CUBA => [COUNTRY_NAME => 'Cuba', COUNTRY_CODE => 'CU', COUNTRY_PHONE => '53', LANGUAGE_CODE => 'es', CURRENCY => 'CUP', TIMEZONE => 'America/Havana'],
				self::SWAZILAND => [COUNTRY_NAME => 'Swaziland', COUNTRY_CODE => 'SZ', COUNTRY_PHONE => '268', LANGUAGE_CODE => 'en', CURRENCY => 'SZL', TIMEZONE => 'Africa/Mbabane'],
				self::SYRIA => [COUNTRY_NAME => 'Syria', COUNTRY_CODE => 'SY', COUNTRY_PHONE => '963', LANGUAGE_CODE => 'ar', CURRENCY => 'SYP', TIMEZONE => 'Asia/Damascus'],
				self::SINT_MAARTEN => [COUNTRY_NAME => 'Sint Maarten', COUNTRY_CODE => 'SX', COUNTRY_PHONE => '599', LANGUAGE_CODE => 'en', CURRENCY => 'ANG', TIMEZONE => 'America/Lower_Princes'],
				self::KYRGYZSTAN => [COUNTRY_NAME => 'Kyrgyzstan', COUNTRY_CODE => 'KG', COUNTRY_PHONE => '996', LANGUAGE_CODE => 'ky', CURRENCY => 'KGS', TIMEZONE => 'Asia/Bishkek'],
				self::KENYA => [COUNTRY_NAME => 'Kenya', COUNTRY_CODE => 'KE', COUNTRY_PHONE => '254', LANGUAGE_CODE => 'en', CURRENCY => 'KES', TIMEZONE => 'Africa/Nairobi'],
				self::SOUTH_SUDAN => [COUNTRY_NAME => 'South Sudan', COUNTRY_CODE => 'SS', COUNTRY_PHONE => '211', LANGUAGE_CODE => 'ar', CURRENCY => 'SSP', TIMEZONE => 'Africa/Juba'],
				self::SURINAME => [COUNTRY_NAME => 'Suriname', COUNTRY_CODE => 'SR', COUNTRY_PHONE => '597', LANGUAGE_CODE => 'nl', CURRENCY => 'SRD', TIMEZONE => 'America/Paramaribo'],
				self::KIRIBATI => [COUNTRY_NAME => 'Kiribati', COUNTRY_CODE => 'KI', COUNTRY_PHONE => '686', LANGUAGE_CODE => 'en', CURRENCY => 'AUD', TIMEZONE => 'Pacific/Tarawa'],
				self::CAMBODIA => [COUNTRY_NAME => 'Cambodia', COUNTRY_CODE => 'KH', COUNTRY_PHONE => '855', LANGUAGE_CODE => 'km', CURRENCY => 'KHR', TIMEZONE => 'Asia/Phnom_Penh'],
				self::SAINT_KITTS_AND_NEVIS => [COUNTRY_NAME => 'Saint Kitts and Nevis', COUNTRY_CODE => 'KN', COUNTRY_PHONE => '1-869', LANGUAGE_CODE => 'en', CURRENCY => 'XCD', TIMEZONE => 'America/St_Kitts'],
				self::COMOROS => [COUNTRY_NAME => 'Comoros', COUNTRY_CODE => 'KM', COUNTRY_PHONE => '269', LANGUAGE_CODE => 'fr', CURRENCY => 'KMF', TIMEZONE => 'Indian/Comoro'],
				self::SAO_TOME_AND_PRINCIPE => [COUNTRY_NAME => 'Sao Tome and Principe', COUNTRY_CODE => 'ST', COUNTRY_PHONE => '239', LANGUAGE_CODE => 'pt', CURRENCY => 'STN', TIMEZONE => 'Africa/Sao_Tome'],
				self::SLOVAKIA => [COUNTRY_NAME => 'Slovakia', COUNTRY_CODE => 'SK', COUNTRY_PHONE => '421', LANGUAGE_CODE => 'sk', CURRENCY => 'EUR', TIMEZONE => 'Europe/Bratislava'],
				self::SOUTH_KOREA => [COUNTRY_NAME => 'South Korea', COUNTRY_CODE => 'KR', COUNTRY_PHONE => '82', LANGUAGE_CODE => 'ko', CURRENCY => 'KRW', TIMEZONE => 'Asia/Seoul'],
				self::SLOVENIA => [COUNTRY_NAME => 'Slovenia', COUNTRY_CODE => 'SI', COUNTRY_PHONE => '386', LANGUAGE_CODE => 'sl', CURRENCY => 'EUR', TIMEZONE => 'Europe/Ljubljana'],
				self::NORTH_KOREA => [COUNTRY_NAME => 'North Korea', COUNTRY_CODE => 'KP', COUNTRY_PHONE => '850', LANGUAGE_CODE => 'ko', CURRENCY => 'KPW', TIMEZONE => 'Asia/Pyongyang'],
				self::KUWAIT => [COUNTRY_NAME => 'Kuwait', COUNTRY_CODE => 'KW', COUNTRY_PHONE => '965', LANGUAGE_CODE => 'ar', CURRENCY => 'KWD', TIMEZONE => 'Asia/Kuwait'],
				self::SENEGAL => [COUNTRY_NAME => 'Senegal', COUNTRY_CODE => 'SN', COUNTRY_PHONE => '221', LANGUAGE_CODE => 'fr', CURRENCY => 'XOF', TIMEZONE => 'Africa/Dakar'],
				self::SAN_MARINO => [COUNTRY_NAME => 'San Marino', COUNTRY_CODE => 'SM', COUNTRY_PHONE => '378', LANGUAGE_CODE => 'it', CURRENCY => 'EUR', TIMEZONE => 'Europe/San_Marino'],
				self::SIERRA_LEONE => [COUNTRY_NAME => 'Sierra Leone', COUNTRY_CODE => 'SL', COUNTRY_PHONE => '232', LANGUAGE_CODE => 'en', CURRENCY => 'SLL', TIMEZONE => 'Africa/Freetown'],
				self::SEYCHELLES => [COUNTRY_NAME => 'Seychelles', COUNTRY_CODE => 'SC', COUNTRY_PHONE => '248', LANGUAGE_CODE => 'fr', CURRENCY => 'SCR', TIMEZONE => 'Indian/Mahe'],
				self::KAZAKHSTAN => [COUNTRY_NAME => 'Kazakhstan', COUNTRY_CODE => 'KZ', COUNTRY_PHONE => '7', LANGUAGE_CODE => 'kk', CURRENCY => 'KZT', TIMEZONE => 'Asia/Almaty'],
				self::CAYMAN_ISLANDS => [COUNTRY_NAME => 'Cayman Islands', COUNTRY_CODE => 'KY', COUNTRY_PHONE => '1-345', LANGUAGE_CODE => 'en', CURRENCY => 'KYD', TIMEZONE => 'America/Cayman'],
				self::SINGAPORE => [COUNTRY_NAME => 'Singapore', COUNTRY_CODE => 'SG', COUNTRY_PHONE => '65', LANGUAGE_CODE => 'en', CURRENCY => 'SGD', TIMEZONE => 'Asia/Singapore'],
				self::SWEDEN => [COUNTRY_NAME => 'Sweden', COUNTRY_CODE => 'SE', COUNTRY_PHONE => '46', LANGUAGE_CODE => 'sv', CURRENCY => 'SEK', TIMEZONE => 'Europe/Stockholm'],
				self::SUDAN => [COUNTRY_NAME => 'Sudan', COUNTRY_CODE => 'SD', COUNTRY_PHONE => '249', LANGUAGE_CODE => 'ar', CURRENCY => 'SDG', TIMEZONE => 'Africa/Khartoum'],
				self::DOMINICAN_REPUBLIC => [COUNTRY_NAME => 'Dominican Republic', COUNTRY_CODE => 'DO', COUNTRY_PHONE => '1-809 and 1-829', LANGUAGE_CODE => 'es', CURRENCY => 'DOP', TIMEZONE => 'America/Santo_Domingo'],
				self::DOMINICA => [COUNTRY_NAME => 'Dominica', COUNTRY_CODE => 'DM', COUNTRY_PHONE => '1-767', LANGUAGE_CODE => 'en', CURRENCY => 'XCD', TIMEZONE => 'America/Dominica'],
				self::DJIBOUTI => [COUNTRY_NAME => 'Djibouti', COUNTRY_CODE => 'DJ', COUNTRY_PHONE => '253', LANGUAGE_CODE => 'fr', CURRENCY => 'DJF', TIMEZONE => 'Africa/Djibouti'],
				self::DENMARK => [COUNTRY_NAME => 'Denmark', COUNTRY_CODE => 'DK', COUNTRY_PHONE => '45', LANGUAGE_CODE => 'da', CURRENCY => 'DKK', TIMEZONE => 'Europe/Copenhagen'],
				self::BRITISH_VIRGIN_ISLANDS => [COUNTRY_NAME => 'British Virgin Islands', COUNTRY_CODE => 'VG', COUNTRY_PHONE => '1-284', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'America/Tortola'],
				self::GERMANY => [COUNTRY_NAME => 'Germany', COUNTRY_CODE => 'DE', COUNTRY_PHONE => '49', LANGUAGE_CODE => 'de', CURRENCY => 'EUR', TIMEZONE => 'Europe/Berlin'],
				self::YEMEN => [COUNTRY_NAME => 'Yemen', COUNTRY_CODE => 'YE', COUNTRY_PHONE => '967', LANGUAGE_CODE => 'ar', CURRENCY => 'YER', TIMEZONE => 'Asia/Aden'],
				self::ALGERIA => [COUNTRY_NAME => 'Algeria', COUNTRY_CODE => 'DZ', COUNTRY_PHONE => '213', LANGUAGE_CODE => 'ar', CURRENCY => 'DZD', TIMEZONE => 'Africa/Algiers'],
				self::UNITED_STATES => [COUNTRY_NAME => 'United States', COUNTRY_CODE => 'US', COUNTRY_PHONE => '1', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'America/New_York'],
				self::URUGUAY => [COUNTRY_NAME => 'Uruguay', COUNTRY_CODE => 'UY', COUNTRY_PHONE => '598', LANGUAGE_CODE => 'es', CURRENCY => 'UYU', TIMEZONE => 'America/Montevideo'],
				self::MAYOTTE => [COUNTRY_NAME => 'Mayotte', COUNTRY_CODE => 'YT', COUNTRY_PHONE => '262', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR', TIMEZONE => 'Indian/Mayotte'],
				self::UNITED_STATES_MINOR_OUTLYING_ISLANDS => [COUNTRY_NAME => 'United States Minor Outlying Islands', COUNTRY_CODE => 'UM', COUNTRY_PHONE => '1', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Pacific/Midway'],
				self::LEBANON => [COUNTRY_NAME => 'Lebanon', COUNTRY_CODE => 'LB', COUNTRY_PHONE => '961', LANGUAGE_CODE => 'ar', CURRENCY => 'LBP', TIMEZONE => 'Asia/Beirut'],
				self::SAINT_LUCIA => [COUNTRY_NAME => 'Saint Lucia', COUNTRY_CODE => 'LC', COUNTRY_PHONE => '1-758', LANGUAGE_CODE => 'en', CURRENCY => 'XCD', TIMEZONE => 'America/St_Lucia'],
				self::LAOS => [COUNTRY_NAME => 'Laos', COUNTRY_CODE => 'LA', COUNTRY_PHONE => '856', LANGUAGE_CODE => 'lo', CURRENCY => 'LAK', TIMEZONE => 'Asia/Vientiane'],
				self::TUVALU => [COUNTRY_NAME => 'Tuvalu', COUNTRY_CODE => 'TV', COUNTRY_PHONE => '688', LANGUAGE_CODE => 'tv', CURRENCY => 'AUD', TIMEZONE => 'Pacific/Funafuti'],
				self::TAIWAN => [COUNTRY_NAME => 'Taiwan', COUNTRY_CODE => 'TW', COUNTRY_PHONE => '886', LANGUAGE_CODE => 'zh', CURRENCY => 'TWD', TIMEZONE => 'Asia/Taipei'],
				self::TRINIDAD_AND_TOBAGO => [COUNTRY_NAME => 'Trinidad and Tobago', COUNTRY_CODE => 'TT', COUNTRY_PHONE => '1-868', LANGUAGE_CODE => 'en', CURRENCY => 'TTD', TIMEZONE => 'America/Port_of_Spain'],
				self::TURKEY => [COUNTRY_NAME => 'Turkey', COUNTRY_CODE => 'TR', COUNTRY_PHONE => '90', LANGUAGE_CODE => 'tr', CURRENCY => 'TRY', TIMEZONE => 'Europe/Istanbul'],
				self::SRI_LANKA => [COUNTRY_NAME => 'Sri Lanka', COUNTRY_CODE => 'LK', COUNTRY_PHONE => '94', LANGUAGE_CODE => 'si', CURRENCY => 'LKR', TIMEZONE => 'Asia/Colombo'],
				self::LIECHTENSTEIN => [COUNTRY_NAME => 'Liechtenstein', COUNTRY_CODE => 'LI', COUNTRY_PHONE => '423', LANGUAGE_CODE => 'de', CURRENCY => 'CHF', TIMEZONE => 'Europe/Vaduz'],
				self::LATVIA => [COUNTRY_NAME => 'Latvia', COUNTRY_CODE => 'LV', COUNTRY_PHONE => '371', LANGUAGE_CODE => 'lv', CURRENCY => 'EUR', TIMEZONE => 'Europe/Riga'],
				self::TONGA => [COUNTRY_NAME => 'Tonga', COUNTRY_CODE => 'TO', COUNTRY_PHONE => '676', LANGUAGE_CODE => 'to', CURRENCY => 'TOP', TIMEZONE => 'Pacific/Tongatapu'],
				self::LITHUANIA => [COUNTRY_NAME => 'Lithuania', COUNTRY_CODE => 'LT', COUNTRY_PHONE => '370', LANGUAGE_CODE => 'lt', CURRENCY => 'EUR', TIMEZONE => 'Europe/Vilnius'],
				self::LUXEMBOURG => [COUNTRY_NAME => 'Luxembourg', COUNTRY_CODE => 'LU', COUNTRY_PHONE => '352', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR', TIMEZONE => 'Europe/Luxembourg'],
				self::LIBERIA => [COUNTRY_NAME => 'Liberia', COUNTRY_CODE => 'LR', COUNTRY_PHONE => '231', LANGUAGE_CODE => 'en', CURRENCY => 'LRD', TIMEZONE => 'Africa/Monrovia'],
				self::LESOTHO => [COUNTRY_NAME => 'Lesotho', COUNTRY_CODE => 'LS', COUNTRY_PHONE => '266', LANGUAGE_CODE => 'en', CURRENCY => 'LSL', TIMEZONE => 'Africa/Maseru'],
				self::THAILAND => [COUNTRY_NAME => 'Thailand', COUNTRY_CODE => 'TH', COUNTRY_PHONE => '66', LANGUAGE_CODE => 'th', CURRENCY => 'THB', TIMEZONE => 'Asia/Bangkok'],
				self::FRENCH_SOUTHERN_TERRITORIES => [COUNTRY_NAME => 'French Southern Territories', COUNTRY_CODE => 'TF', COUNTRY_PHONE => '', LANGUAGE_CODE => 'fr', CURRENCY => 'EUR', TIMEZONE => 'Indian/Kerguelen'],
				self::TOGO => [COUNTRY_NAME => 'Togo', COUNTRY_CODE => 'TG', COUNTRY_PHONE => '228', LANGUAGE_CODE => 'fr', CURRENCY => 'XOF', TIMEZONE => 'Africa/Lome'],
				self::CHAD => [COUNTRY_NAME => 'Chad', COUNTRY_CODE => 'TD', COUNTRY_PHONE => '235', LANGUAGE_CODE => 'fr', CURRENCY => 'XAF', TIMEZONE => 'Africa/Ndjamena'],
				self::TURKS_AND_CAICOS_ISLANDS => [COUNTRY_NAME => 'Turks and Caicos Islands', COUNTRY_CODE => 'TC', COUNTRY_PHONE => '1-649', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'America/Grand_Turk'],
				self::LIBYA => [COUNTRY_NAME => 'Libya', COUNTRY_CODE => 'LY', COUNTRY_PHONE => '218', LANGUAGE_CODE => 'ar', CURRENCY => 'USD', TIMEZONE => 'Africa/Tripoli'],
				self::VATICAN => [COUNTRY_NAME => 'Vatican', COUNTRY_CODE => 'VA', COUNTRY_PHONE => '379', LANGUAGE_CODE => 'it', CURRENCY => 'EUR', TIMEZONE => 'Europe/Vatican'],
				self::SAINT_VINCENT_AND_THE_GRENADINES => [COUNTRY_NAME => 'Saint Vincent and the Grenadines', COUNTRY_CODE => 'VC', COUNTRY_PHONE => '1-784', LANGUAGE_CODE => 'en', CURRENCY => 'XCD', TIMEZONE => 'America/St_Vincent'],
				self::UNITED_ARAB_EMIRATES => [COUNTRY_NAME => 'United Arab Emirates', COUNTRY_CODE => 'AE', COUNTRY_PHONE => '971', LANGUAGE_CODE => 'ar', CURRENCY => 'AED', TIMEZONE => 'Asia/Dubai'],
				self::ANDORRA => [COUNTRY_NAME => 'Andorra', COUNTRY_CODE => 'AD', COUNTRY_PHONE => '376', LANGUAGE_CODE => 'ca', CURRENCY => 'EUR', TIMEZONE => 'Europe/Andorra'],
				self::ANTIGUA_AND_BARBUDA => [COUNTRY_NAME => 'Antigua and Barbuda', COUNTRY_CODE => 'AG', COUNTRY_PHONE => '1-268', LANGUAGE_CODE => 'en', CURRENCY => 'XCD', TIMEZONE => 'America/Antigua'],
				self::AFGHANISTAN => [COUNTRY_NAME => 'Afghanistan', COUNTRY_CODE => 'AF', COUNTRY_PHONE => '93', LANGUAGE_CODE => 'ps', CURRENCY => 'AFN', TIMEZONE => 'Asia/Kabul'],
				self::ANGUILLA => [COUNTRY_NAME => 'Anguilla', COUNTRY_CODE => 'AI', COUNTRY_PHONE => '1-264', LANGUAGE_CODE => 'en', CURRENCY => 'XCD', TIMEZONE => 'America/Anguilla'],
				self::US_VIRGIN_ISLANDS => [COUNTRY_NAME => 'U.S. Virgin Islands', COUNTRY_CODE => 'VI', COUNTRY_PHONE => '1-340', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'America/St_Thomas'],
				self::ICELAND => [COUNTRY_NAME => 'Iceland', COUNTRY_CODE => 'IS', COUNTRY_PHONE => '354', LANGUAGE_CODE => 'is', CURRENCY => 'ISK', TIMEZONE => 'Atlantic/Reykjavik'],
				self::IRAN => [COUNTRY_NAME => 'Iran', COUNTRY_CODE => 'IR', COUNTRY_PHONE => '98', LANGUAGE_CODE => 'fa', CURRENCY => 'IRR', TIMEZONE => 'Asia/Tehran'],
				self::ARMENIA => [COUNTRY_NAME => 'Armenia', COUNTRY_CODE => 'AM', COUNTRY_PHONE => '374', LANGUAGE_CODE => 'hy', CURRENCY => 'AMD', TIMEZONE => 'Asia/Yerevan'],
				self::ALBANIA => [COUNTRY_NAME => 'Albania', COUNTRY_CODE => 'AL', COUNTRY_PHONE => '355', LANGUAGE_CODE => 'sq', CURRENCY => 'ALL', TIMEZONE => 'Europe/Tirane'],
				self::ANGOLA => [COUNTRY_NAME => 'Angola', COUNTRY_CODE => 'AO', COUNTRY_PHONE => '244', LANGUAGE_CODE => 'pt', CURRENCY => 'AOA', TIMEZONE => 'Africa/Luanda'],
				self::ANTARCTICA => [COUNTRY_NAME => 'Antarctica', COUNTRY_CODE => 'AQ', COUNTRY_PHONE => '', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Antarctica/Palmer'],
				self::AMERICAN_SAMOA => [COUNTRY_NAME => 'American Samoa', COUNTRY_CODE => 'AS', COUNTRY_PHONE => '1-684', LANGUAGE_CODE => 'en', CURRENCY => 'USD', TIMEZONE => 'Pacific/Pago_Pago'],
				self::ARGENTINA => [COUNTRY_NAME => 'Argentina', COUNTRY_CODE => 'AR', COUNTRY_PHONE => '54', LANGUAGE_CODE => 'es', CURRENCY => 'ARS', TIMEZONE => 'America/Argentina/Buenos_Aires'],
				self::AUSTRALIA => [COUNTRY_NAME => 'Australia', COUNTRY_CODE => 'AU', COUNTRY_PHONE => '61', LANGUAGE_CODE => 'en', CURRENCY => 'AUD', TIMEZONE => 'Australia/Sydney'],
				self::AUSTRIA => [COUNTRY_NAME => 'Austria', COUNTRY_CODE => 'AT', COUNTRY_PHONE => '43', LANGUAGE_CODE => 'de', CURRENCY => 'EUR', TIMEZONE => 'Europe/Vienna'],
				self::ARUBA => [COUNTRY_NAME => 'Aruba', COUNTRY_CODE => 'AW', COUNTRY_PHONE => '297', LANGUAGE_CODE => 'nl', CURRENCY => 'AWG', TIMEZONE => 'America/Aruba'],
				self::INDIA => [COUNTRY_NAME => 'India', COUNTRY_CODE => 'IN', COUNTRY_PHONE => '91', LANGUAGE_CODE => 'en', CURRENCY => 'INR', TIMEZONE => 'Asia/Kolkata'],
				self::ALAND_ISLANDS => [COUNTRY_NAME => 'Aland Islands', COUNTRY_CODE => 'AX', COUNTRY_PHONE => '358-18', LANGUAGE_CODE => 'sv', CURRENCY => 'EUR', TIMEZONE => 'Europe/Mariehamn'],
				self::AZERBAIJAN => [COUNTRY_NAME => 'Azerbaijan', COUNTRY_CODE => 'AZ', COUNTRY_PHONE => '994', LANGUAGE_CODE => 'az', CURRENCY => 'AZN', TIMEZONE => 'Asia/Baku'],
				self::IRELAND => [COUNTRY_NAME => 'Ireland', COUNTRY_CODE => 'IE', COUNTRY_PHONE => '353', LANGUAGE_CODE => 'en', CURRENCY => 'EUR', TIMEZONE => 'Europe/Dublin'],
				self::INDONESIA => [COUNTRY_NAME => 'Indonesia', COUNTRY_CODE => 'ID', COUNTRY_PHONE => '62', LANGUAGE_CODE => 'id', CURRENCY => 'IDR', TIMEZONE => 'Asia/Jakarta'],
				self::UKRAINE => [COUNTRY_NAME => 'Ukraine', COUNTRY_CODE => 'UA', COUNTRY_PHONE => '380', LANGUAGE_CODE => 'uk', CURRENCY => 'UAH', TIMEZONE => 'Europe/Kiev'],
				self::QATAR => [COUNTRY_NAME => 'Qatar', COUNTRY_CODE => 'QA', COUNTRY_PHONE => '974', LANGUAGE_CODE => 'ar', CURRENCY => 'QAR', TIMEZONE => 'Asia/Qatar'],
				self::MOZAMBIQUE => [COUNTRY_NAME => 'Mozambique', COUNTRY_CODE => 'MZ', COUNTRY_PHONE => '258', LANGUAGE_CODE => 'pt', CURRENCY => 'USD', TIMEZONE => 'Africa/Maputo']
			};

			return (empty($_type)) ? $country : $country[$_type];
		}

		public static function find(string $_country_code): self | false
		{
			$_country_code = strtoupper($_country_code);

			return match($_country_code)
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