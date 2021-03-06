<?php
namespace App\Utility;

use Cake\Cache\Cache;
use Cake\I18n\Time;
use Cmfcmf\OpenWeatherMap;
use Cmfcmf\OpenWeatherMap\WeatherForecast;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;
use Laminas\Diactoros\RequestFactory;

class Weather extends OpenWeatherMap
{
    const ICON = [
        '01d' => '☀️',
        '01n' => '🌕',
        '02d' => '🌤',
        '02n' => '🌤',
        '03d' => '🌥',
        '03n' => '🌥',
        '04d' => '☁️',
        '04n' => '☁️',
        '09d' => '🌧',
        '09n' => '🌧',
        '10d' => '🌦',
        '10n' => '🌦',
        '11d' => '🌩',
        '11n' => '🌩',
        '13d' => '❄️',
        '13n' => '❄️',
        '50d' => '💨',
        '50n' => '💨',
    ];

    /**
     * Weather constructor.
     *
     * @param $apiKey
     * @param null $cache
     * @param int $ttl
     */
    public function __construct($apiKey, $cache = null, $ttl = 600)
    {
        parent::__construct($apiKey, GuzzleAdapter::createWithConfig([]), new RequestFactory(), $cache, $ttl);
    }

    /**
     * Return km/h speed of wind
     * example: '18 km/h'
     *
     * @param $speedInMetersPerSec
     * @return string
     */
    protected function _getSpeedMessageInKmH(float $speedInMetersPerSec): string
    {
        return (int)($speedInMetersPerSec * 3.6) . ' km/h';
    }

    /**
     * @param float $now
     * @param float $min
     * @param float $max
     * @param float $wind
     * @param string $icon
     * @return string
     */
    protected function _buildCurrentWeatherMessage(float $now, float $min, float $max, float $wind, string $icon): string
    {
        $wind = $this->_getSpeedMessageInKmH($wind);

        $now = $this->_formatTemperature($now);
        $min = $this->_formatTemperature($min);
        $max = $this->_formatTemperature($max);

        $title = __('Now') . ' (' . __('Updated at') . ' ' . Time::now()->format('H:i') . '):';
        return $title . PHP_EOL . self::ICON[$icon] . " {$now}°    {$max}°/{$min}°    $wind";
    }

    /**
     * @param $date
     * @param $min
     * @param $max
     * @param $wind
     * @param $icon
     * @return string
     */
    protected function _buildDailyForecastMessage(Time $date, float $min, float $max, float $wind, string $icon)
    {
        $wind = $this->_getSpeedMessageInKmH($wind);
        $date = $date->format('d.m');

        $min = $this->_formatTemperature($min);
        $max = $this->_formatTemperature($max);

        return $date . '   ' . self::ICON[$icon] . "   {$max}°/{$min}°        $wind" . PHP_EOL;
    }

    /**
     * Adds + to temp > 0 or adds space to 0 to prevent the message looking as telegram command
     *
     * @param $temperature
     * @return string
     */
    protected function _formatTemperature($temperature): string
    {
        return $temperature == 0 ? ' ' . $temperature : ($temperature > 0 ? '+' . $temperature : $temperature);
    }

    /**
     * @param WeatherForecast $forecast
     * @return string
     */
    public function getCurrentWeatherMessage(WeatherForecast $forecast): string
    {
        $tempRange = [];

        foreach ($forecast as $i => $weather) {

            if ($i > 8) {
                break;
            }

            if ($i == 0) {
                $wind = $weather->wind->speed->getValue();
                $icon = $weather->weather->icon;
            }

            $tempRange[] = (int) $weather->temperature->now->getValue();
        }

        $now = $tempRange[0];
        $min = min($tempRange);
        $max = max($tempRange);

        return $this->_buildCurrentWeatherMessage($now, $min, $max, $wind, $icon);
    }

    /**
     * @param WeatherForecast $forecast
     * @return string
     * @throws OpenWeatherMap\Exception
     */
    public function getDailyForecastMessage(WeatherForecast $forecast): string
    {
        $tzHours = (int) $forecast->city->timezone->getName();

        $todayDate = Time::now()->addHours($tzHours);
        $todayDatePlus5days = Time::now()->addDays(5)->addHours($tzHours);

        $message = $todayDate->format('d.m  H:i  ') . $forecast->city->name . PHP_EOL . PHP_EOL;

        $i = 0;
        $icon = null;
        $tempRange = [];
        $windRange = [];

        /**
         * Day changes every 8th iteration
         */
        foreach ($forecast as $weather) {

            $date = Time::createFromTimestamp($weather->time->from->getTimestamp())->addHours($tzHours);

            if ($date->isSameDay($todayDate) || $date->isSameDay($todayDatePlus5days)) {
                continue;
            }

            $tempRange[] = (int) $weather->temperature->now->getValue();
            $windRange[] = (int) $weather->wind->speed->getValue();

            if ($i == 5) {
                $icon = $weather->weather->icon;
            }

            if ($i == 7) {

                $min = min($tempRange);
                $max = max($tempRange);
                $wind = array_sum($windRange) / 8;

                $message .= $this->_buildDailyForecastMessage($date, $min, $max, $wind, $icon);

                $i = 0;
                $tempRange = [];
                $windRange = [];

                continue;
            }

            $i++;
        }

        return $message;
    }

    /**
     * Get forecast weather data, cached if called less that hour ago
     *
     * @param $cityId
     * @param string $languageCode
     * @return OpenWeatherMap\WeatherForecast|mixed
     * @throws OpenWeatherMap\Exception
     */
    public function getSimpleForecast($cityId, $languageCode = 'en')
    {
        $result = Cache::read($cityId, 'weatherData');

        if (!$result) {
            $result = $this->getWeatherForecast($cityId, 'metric', $languageCode, '', 5);
            Cache::write($cityId, $result, 'weatherData');
        }

        return $result;
    }
}
