<?php
/*
 * PHP code protect
 *
 * @link 		https://github.com/Mofsy/pcp-cs
 * @author		Oleg Budrin <ru.mofsy@yandex.ru>
 * @copyright	Copyright (c) 2013-2015, Oleg Budrin (Mofsy)
 */

class ProtectServer {

	/*
	 * Префикс таблиц базы данных
	 *
	 * @var string
	 */
	private $_db_prefix = 'pcp';

	/*
	 * Объект базы данных
	 *
	 * @var object
	 */
	private $_db;


	/*
	 * Конструктор класса
	 */
	public function __construct($db_host, $db_user, $db_pass, $db_name, $db_prefix)
	{
		$this->_db_prefix = $db_prefix;

		include_once('database.class.php');
		$this->_db = new Db($db_user, $db_pass, $db_name, $db_host);
	}

	/*
	 * Деструктор класа
	 */
	public function __destruct()
	{
		$this->_db->close();
	}

	/*
	 * Запускаем сервер на прослушивание запроов от клиента
	 */
	public function run()
	{
		// TODO: Сделать занесение всех обращений в таблицу логов базы данных

		if ($client_data = $this->clientDataGet())
		{
			/*
			 * Запрашиваем все данные о лицензионном ключе из базы данных по ключу клиента
			 */
			if($key_data = $this->licenseKeyGet($client_data['key']))
			{
				/*
			     * Если лицензионный ключ не активирован
				 */
				if ($key_data['status'] == 0)
				{
					$key_data = $this->licenseKeyActivate($client_data);
				}

				/*
				 * Запрашиваем все необходимое о методе из базы данных по полученному ID
				 */
				$method_data = $this->licenseKeyMethodGet($key_data['method_id']);

				/*
				 * Создаем локальный ключ
				 */
				$local_key = $this->localKeyCreate($key_data, $method_data);

				/*
				 * Скармливаем клиенту локальный ключ
				 */
				die($local_key);
			}

			die('Invalid');
		}
		else
		{
			die('Invalid');
		}
	}

	/*
	 * Генерация локального ключа
	 *
	 * @param string $license_key лицензионный ключ
	 * @param string $domain доменное имя, на котором активирована лицензия
	 */

	public function localKeyCreate($key_data, $method_data)
	{

		/*
		 * Массив с указателями проверки
		 * Можно проверять домен, айпи адрес, имя хоста
		 */
		$instance = array();

		$instance['domain'] = array(0 => "$domain", 1 => "www.$domain");

		/*
		 * Уникальный идентификатор клиента
		 */
		$key_data['customer'] = $license_user_id;

		/*
		 * Уникальный логин клиента на сайте
		 */
		$key_data['user'] = $license_user_name;

		/*
		 * Лицензионный ключ
		 */
		$key_data['license_key_string'] = $license_key;

		/*
		 * Данные о том, что следует проверять
		 */
		$key_data['instance'] = $instance;

		/*
		 * Маркер проверки, указывает на то, что надо проверять в данных
		 */
		$key_data['enforce'] = $enforce;

		/*
		 * Кастомные поля, не учитываются
		 */
		$key_data['custom_fields'] = array();

		/*
		 * Время истечения срока скачивания модуля
		 */
		$key_data['download_access_expires'] = 0;

		/*
		 * Время истечения срока поддержки
		 */
		$key_data['support_access_expires'] = 0;

		/*
		 * Дата окончания лицензии в Unix-времени
		 */
		$key_data['license_expires'] = $license_expires;

		/*
		 * Время истечения локального ключа
		 * берем количество дней из метода, умножаем его на количество секунд в сутках и прибавляем к Unix-времени.
		 */
		$key_data['local_key_expires'] = ((integer)$check_period * 86400) + time();

		/*
		 * Статус лицензии, если вернуть другой, то лицензия перестанет работать
		 */
		if ($license_status == 0 || $license_status == 1 || $license_status == 3)
			$status = 'Active';

		$key_data['status'] = strtolower($status);

		/*
		 * Сериализуем все данные лицензии
		 */
		$key_data = serialize($key_data);

		/*
		 * Конечная обработка всех данных
		 */
		$license_info = array();
		$license_info[0] = $key_data;
		$license_info[1] = md5($secret_key.$license_info[0]);
		$license_info[2] = md5( microtime() );
		$license_info = base64_encode(implode( "{protect}", $license_info ));

		return urlencode( wordwrap( $license_info, 64, "\n", 1 ) );
	}

	/*
	 * Создание лицензионного ключа
	 *
	 * @return string 25 значный ключ активации (5 пар по 5)
	 */
	public function licenseKeyCreate()
	{
		$key = md5(mktime());
		$new_key = '';
		for ($i = 1; $i <= 25; $i++)
		{
			$new_key .= $key[$i];
			if ($i % 5 == 0 && $i != 25) $new_key .= '-';
		}

		return strtoupper($new_key);
	}

	/*
	 * Получение информации о методе проверки лицензионного ключа по id
	 *
	 * @param integer $license_key_method_id Идентификатор метода проверки лицензионного ключа
	 * @return array|boolean Массив с информацией о методе, либо false при отсутствие метода
	 */
	public function licenseKeyMethodGet($license_key_method_id)
	{
		$method_data = array();

		$result = $this->_db->query("SELECT * FROM " . $this->_db_prefix . "_license_methods WHERE id='{$license_key_method_id}'");
		$row = $this->_db->get_row($result);

		/*
		 * Секретный ключ метода
		 */
		$method_data['secret_key'] = $row['secret_key'];

		/*
		 * Маркер того, что проверять
		 */
		$method_data['enforce'] = explode(",", $row['enforce']);

		/*
		 * Период проверки локального ключа в днях
		 */
		$method_data['check_period'] = $row['check_period'];

		return $method_data;
	}

	/*
	 * Получение всей информации о лицензионном ключе по ключу
	 *
	 * @return array|boolean Массив с информацией о ключе, либо false при отсутствие ключа
	 */
	public function licenseKeyGet($key)
	{

		$result = $this->_db->query("SELECT * FROM " . $this->_db_prefix . "_license_keys WHERE l_key='$key' LIMIT 0,1");
		$row = $this->_db->get_row($result);

		if(count($row) == 1)
		{
			$key_data = array();

			/*
			 * Идентификатор лицензионного ключа
			 */
			$key_data['id'] = $row['id'];

			/*
			 * Лицензионный ключ активации
			 */
			$key_data['key'] = $row['l_key'];

			/*
			 * Идентификатор клиента на сайте
			 */
			$key_data['user_id'] = $row['user_id'];

			/*
			 * Логин клиента на сайте
			 */
			$key_data['user_name'] = $row['user_name'];

			/*
			 * Доменное имя лицензии, если она была активирована
			 */
			$key_data['domain'] = $row['l_domain'];

			/*
			 * Разрешено ли использовать на поддоменах
			 *
			 * 1 - разрешено
			 * 0 - запрещено
			 */
			$key_data['domain_wildcard'] = $row['l_domain_wildcard'];

			/*
			 * Айпи адрес сервера
			 */
			$key_data['ip'] = $row['l_ip'];

			/*
			 * Директория где находится клиент
			 */
			$key_data['directory'] = $row['l_directory'];

			/*
			 * Название хоста где находится клиент
			 */
			$key_data['server_hostname'] = $row['l_server_hostname'];

			/*
			 * Айпи адрес хоста где находится клиент
			 */
			$key_data['server_ip'] = $row['l_server_ip'];

			/*
			 * Статус лицензии
			 *
			 * 0 - не активирована
			 * 1 - лицензия активирована
			 * 2 - срок истек
			 * 3 - лицензия переиздана (сделано продление)
			 */
			$key_data['status'] = $row['l_status'];

			/*
			 * Метод проверки лицензионного ключа
			 */
			$key_data['method_id'] = $row['l_method_id'];

			/*
			 * Дата истечения срока действия лицензионного ключа в UNIX формате
			 */
			$key_data['expires'] = $row['l_expires'];

			return $key_data;
		}

		return false;
	}

	/*
	 * Активация лицензионного ключа
	 */
	public function licenseKeyActivate($client_data)
	{
		$this->_db->query("UPDATE " . $this->_db_prefix . "_license_keys SET l_domain='{$client_data['domain']}', l_ip='{$client_data['ip']}', l_directory='{$client_data['directory']}', l_server_hostname='{$client_data['server_hostname']}', l_server_ip = '{$client_data['server_ip']}', l_status='1' WHERE l_key='{$client_data['key']}'");

		return $this->licenseKeyGet($client_data['key']);
	}

	/*
	 * Сброс активационных данных у ключа активации по ключу активации
	 */
	public function licenseKeyTruncateByKey($license_key)
	{

	}

	/*
	 * Получение данных пришедших от клиента
	 *
	 * @return array|boolean Данные в виде массива в случае успеха, false в случае ошибки
	 */
	public function clientDataGet()
	{
		/*
		 * Проверяем наличие пост запроса от клиента
		 */
		if ($_POST['license_key'])
		{
			$client_data = array();

			/*
	   		 * Лицензионный ключ активации
			 */
			$client_data['key'] = $this->_db->filter(htmlspecialchars(trim(strip_tags(strval($_POST['license_key'])))));

			/*
			 * Домен на котором установлен клиент (без www)
			 */
			$client_data['domain'] = $this->_db->filter(htmlspecialchars(trim(strip_tags(strval($_POST['domain'])))));
			$client_data['domain'] = str_replace("www.", "", $client_data['domain']);

			/*
			 * Айпи адрес клиента
			 */
			$client_data['ip'] = $_POST['ip'];

			/*
			 * Директория от root где установлен клиент
			 */
			$client_data['directory'] = $this->_db->filter(htmlspecialchars(trim(strip_tags(strval($_POST['directory'])))));

			/*
			 * Имя хоста где установлен лиент
			 */
			$client_data['server_hostname'] = $this->_db->filter(htmlspecialchars(trim(strip_tags(strval($_POST['server_hostname'])))));

			/*
			 * Айпи адрес сервера где установлен клиент
			 */
			$client_data['server_ip'] = $this->_db->filter(htmlspecialchars(trim(strip_tags($_POST['server_ip']))));

			return $client_data;
		}

		return false;
	}
} 