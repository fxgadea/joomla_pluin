<?php

/**
 * @package     OneSignal Plugin
 *
 * @copyright   Copyright (C) 2020. All rights reserved
 * @license     MIT License; see LICENSE
 */

// namespace Joomla\plugins\content\onesignalplugin;

defined('_JEXEC') or die;


// Import lib
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri as JUri;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Associations;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Router\Route;
use Joomla\Component\Content\Administrator\Extension\ContentComponent;
use Joomla\Component\Content\Site\Helper\RouteHelper;

/**
 * Plugin push notifications to OneSignal
 * 
 * Este plugin envía notificaciones push a través de OneSignal cuando se crea un nuevo artículo en Joomla.
 */
class plgContentOneSignalPlugin extends CMSPlugin
{

    /**
     * Función que se ejecuta después de guardar el contenido en Joomla.
     * Envía una notificación push a través del plugin OneSignal cuando se crea un nuevo artículo que cumple con ciertos criterios.
     *
     * @param string $context Contexto en el que se guarda el contenido. Puede ser `com_content.article` o `com_content.form`.
     * @param object $article Objeto que representa el artículo que se ha guardado.
     * @param bool $isNew Booleano que indica si el artículo es nuevo.
     */
    public function onContentAfterSave($context, $article, $isNew)
    {

        // Paso 1: Registro de activación del plugin
        Log::add('Paso - 1 - OneSignal Plugin activated', Log::INFO, 'onesignal-plugin');

        // Paso 2: Verificación del contexto y si el artículo es nuevo
        if (isset($context) && ($context == "com_content.article" || $context == "com_content.form") && $isNew) {
            Log::add('Paso - 2 - New article detected', Log::INFO, 'onesignal-plugin');

            // Paso 3: Verificar si el artículo está en la papelera
            if ($this->isArticleInTrash($article->id)) {
                Log::add('Paso - 3 - El artículo está en la papelera y no se procesará.', Log::INFO, 'onesignal-plugin');
                return;
            } else {
                Log::add('Paso - 3 - El artículo no está en la papelera se procesará.', Log::INFO, 'onesignal-plugin');
            }

            // Paso 4: Verificar si la URL del artículo está duplicada
            if ($this->isUrlDuplicated($article->alias)) {
                Log::add('Paso - 4 - La URL del artículo está duplicada y no se procesará.', Log::INFO, 'onesignal-plugin');
                return;
            } else {
                Log::add('Paso - 4 - La URL del artículo no está duplicada se procesará..', Log::INFO, 'onesignal-plugin');
            }

            // Paso 5: Verificar si el título del artículo está duplicado
            if ($this->isTitleDuplicated($article->title)) {
                Log::add('Paso - 5 - El título del artículo está duplicado y no se procesará.', Log::INFO, 'onesignal-plugin');

                // Obtener una instancia del objeto de la aplicación de Joomla
                $application = JFactory::getApplication();
                // Agregar un mensaje a la cola de mensajes
                $application->enqueueMessage(JText::_('El título del artículo está duplicado y no sera transmitida'), 'warning');
                return;
            } else {
                Log::add('Paso - 5 - El titulo del artículo no está duplicada se procesará..', Log::INFO, 'onesignal-plugin');
            }

            // Paso 6: Verificar si el artículo está publicado
            if (!$this->isArticlePublished($article->id)) {
                Log::add('Paso - 6 -El artículo no está publicado y no se procesará.', Log::INFO, 'onesignal-plugin');
                return;
            } else {
                Log::add('Paso - 6 - El artículo está publicado se procesará..', Log::INFO, 'onesignal-plugin');
            }

            // Paso 7: Obtención de parámetros del plugin
            $categories = $this->params->get('categories', '');
            $featured = $this->params->get('featured', 1);
            Log::add('Paso - 7 - Preparando parametros del Pugin.', Log::INFO, 'onesignal-plugin');

            // Paso 8: Verificación de criterios para generar notificación
            if ($article->featured >= $featured && ($categories == '' || (isset($article->catid) && in_array($article->catid, $categories)))) {
                Log::add('Paso - 8 -Notification will be generated', Log::INFO, 'onesignal-plugin');

                // Paso 9: Generación y envío de la notificación push
                $this->sendPushNotification($article->title, $this->getLinkToArticle($article), $this->getArticleImage($article));
                // $this->sendPushNotification($article->title, $this->getLinkToArticle($article));
                Log::add('Paso - 9 - Notification generated', Log::INFO, 'onesignal-plugin');

                // Obtener una instancia del objeto de la aplicación de Joomla
                $application = JFactory::getApplication();
                // Agregar un mensaje a la cola de mensajes
                $application->enqueueMessage(JText::_('Notification generated y transmitida'), 'message');
            }
        }
    }

    /**
     * Verifica si un artículo está en la papelera.
     *
     * @param int $articleId ID del artículo a verificar.
     * @return bool True si el artículo está en la papelera, False en caso contrario.
     */
    private function isArticleInTrash($articleId)
    {
        // Cargar el artículo usando el ID
        $articleTable = JTable::getInstance('content');
        $articleTable->load($articleId);

        // Verificar el estado del artículo
        // En Joomla, el estado -2 generalmente indica que el artículo está en la papelera
        return false; // $articleTable->state == -2;
    }

    /**
     * Verifica si una URL está duplicada.
     *
     * @param string $alias Alias del artículo a verificar.
     * @return bool True si la URL está duplicada, False en caso contrario.
     */
    private function isUrlDuplicated($alias)
    {
        // Crear una instancia de la base de datos
        $db = JFactory::getDbo();

        // Crear una consulta para verificar si el alias ya existe
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->select($db->quoteName('alias'))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('alias') . ' = ' . $db->quote($alias))
            ->where($db->quoteName('state') . ' != -2'); // Excluir artículos en la papelera

        // Establecer la consulta y cargar el resultado
        $db->setQuery($query);
        $count = $db->loadResult();


        // Depuración: Imprimir el valor de $count
        Log::add('Si el alias existe mas de una ves esta duplicado:  ' . $count, Log::INFO, 'onesignal-plugin');

        // Si el conteo es mayor que cero, el título está duplicado
        return $count > 1;
    }

    /**
     * Verifica si un título está duplicado.
     *
     * @param string $title Título del artículo a verificar.
     * @return bool True si el título está duplicado, False en caso contrario.
     */
    private function isTitleDuplicated($title)
    {
        // Crear una instancia de la base de datos
        $db = JFactory::getDbo();

        // Crear una consulta para verificar si el título ya existe
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->select($db->quoteName('title'))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('title') . ' = ' . $db->quote($title))
            ->where($db->quoteName('state') . ' != -2'); // Excluir artículos en la papelera

        // Establecer la consulta y cargar el resultado
        $db->setQuery($query);
        $count = $db->loadResult();


        // Depuración: Imprimir el valor de $count
        Log::add('Si el título existe mas de una ves esta duplicado: ' . $count, Log::INFO, 'onesignal-plugin');

        // Si el conteo es mayor que cero, el título está duplicado
        return $count > 1;
    }
    /**
     * debuelve la categoria del articulo
     *
     * @param string $title Título del artículo a verificar.
     * @return string conteniendo la categoria del articulo.
     */

    private function getArticleCategoryName($title)
    {
        // Crear una instancia de la base de datos
        $db = JFactory::getDbo();

        // Crear una consulta para obtener el nombre de la categoría del artículo
        $query = $db->getQuery(true)
            ->select($db->quoteName('c.title'))
            ->from($db->quoteName('#__content', 'a'))
            ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON ' . $db->quoteName('a.catid') . ' = ' . $db->quoteName('c.id'))
            ->where($db->quoteName('a.title') . ' = ' . $db->quote($title))
            ->where($db->quoteName('a.state') . ' != -2'); // Excluir artículos en la papelera

        // Depuración: Imprimir la consulta SQL
        Log::add('Consulta SQL: ' . $query, Log::INFO, 'onesignal-plugin');

        // Establecer la consulta y cargar el resultado
        $db->setQuery($query);
        $categoryName = $db->loadResult();

        // Depuración: Imprimir el valor de $categoryName
        Log::add('Nombre de la categoría del artículo: ' . $categoryName, Log::INFO, 'onesignal-plugin');

        // Manejo de errores: Verificar si $categoryName es null
        if ($categoryName === null) {
            Log::add('No se encontró la categoría para el artículo: ' . $title, Log::WARNING, 'onesignal-plugin');
            return 'Categoría no encontrada';
        }
        // Devolver el nombre de la categoría
        return $categoryName;
    }

    /**
     * Verifica si un artículo está publicado.
     *
     * @param int $articleId ID del artículo a verificar.
     * @return bool True si el artículo está publicado, False en caso contrario.
     */
    private function isArticlePublished($articleId)
    {
        // Paso 1: Cargar el artículo usando el ID
        $articleTable = JTable::getInstance('content');
        if (!$articleTable->load($articleId)) {
            Log::add('No se pudo cargar el artículo con ID: ' . $articleId, Log::ERROR, 'onesignal-plugin');
            return false;
        }

        // Paso 2: Verificar el estado del artículo
        // En Joomla, el estado 1 generalmente indica que el artículo está publicado
        $isPublished = ($articleTable->state == 1);

        // Registrar el estado del artículo
        Log::add('El artículo con ID: ' . $articleId . ' está ' . ($isPublished ? 'publicado' : 'no publicado'), Log::INFO, 'onesignal-plugin');

        return $isPublished;
    }


    /**
     * Obtiene la URL de la imagen completa de un artículo.
     *
     * @param object $article Objeto del artículo.
     * @return string URL de la imagen del artículo.
     */
    private function getArticleImage($article)
    {
        // Paso 1: Obtener el ID del artículo recién guardado
        $articleId = $article->id;

        // Paso 2: Cargar el artículo usando el ID
        $articleTable = JTable::getInstance('content');
        if (!$articleTable->load($articleId)) {
            Log::add('No se pudo cargar el artículo con ID: ' . $articleId, Log::ERROR, 'onesignal-plugin');
            return '';
        }

        // Paso 3: Verificar si el campo de la imagen está definido
        $image = '';
        if (!empty($articleTable->images)) {
            $images = json_decode($articleTable->images);
            if (!empty($images->image_fulltext)) {
                $image = rtrim(JUri::root(), '/') . '/' . $images->image_fulltext;
            } else {
                Log::add('URL de la imagen: No existe imagen completa definida.', Log::INFO, 'onesignal-plugin');
            }
        } else {
            Log::add('URL de la imagen: No existe campo de imágenes.', Log::INFO, 'onesignal-plugin');
        }

        // Paso 4: Limpiar la URL de la imagen
        if (!empty($image)) {
            $parsed_url = parse_url($image);
            if (isset($parsed_url['scheme'], $parsed_url['host'], $parsed_url['path'])) {
                $image = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'];
                Log::add('URL de la imagen limpia: ' . $image, Log::INFO, 'onesignal-plugin');
            } else {
                Log::add('URL de la imagen no válida: ' . $image, Log::ERROR, 'onesignal-plugin');
                $image = '';
            }
        }

        return $image;
    }

    /**
     * Envía una notificación push a OneSignal.
     *
     * @param string $article_title Título del artículo.
     * @param string $article_link Enlace al artículo.
     * @param string $article_image URL de la imagen del artículo.
     * @return mixed Resultado de la solicitud HTTP.
     */
    private function sendPushNotification($article_title, $article_link, $article_image)
    {
        // Paso 1: Obtener la configuración del plugin
        $onesignal_app_id = $this->params->get('oneSignalAppId', '');
        $onesignal_rest_key = $this->params->get('oneSignalRestKey', '');

        // aqui se forma el titulo del mensaje
        $Mensaje_type = $this->params->get('Mesa_Title', '');

        switch ($Mensaje_type) {
                // El mensaje normal, definido por el usuario.
            case "0":
                $message_title = $this->params->get('messageTitle', 'New article');
                break;

                // El mensaje es la categoria de la informacion transmitida, ejem: deportes, farandula, internacionales etc.
            case "1":
                $message_title = $this->getArticleCategoryName($article_title);
                break;

                // aqui es mas complejo ya que se le agrega al titulo del mensaje un prefijo, ejem: Noticias de deportes, etc.    
            case "2":
                $message_title = $this->params->get('PrefijoTitle', '') . " " . $this->getArticleCategoryName($article_title);
                break;
            default:
                $message_title = 'New article';
                break;
        }

        // aqui se identifica si se usa un segmento de prueba o un segemto definido
        $Test_segment =  $this->params->get('Test_segment', 0);
        if ($Test_segment) {
            $segments = $this->params->get('testsegments', 'Subscribed Users');
        } else {
            $segments = $this->params->get('segments', 'Subscribed Users');
        }

        $language = $this->params->get('language', 'en');

        // Verificar que los parámetros necesarios no estén vacíos
        if (empty($onesignal_app_id) || empty($onesignal_rest_key)) {
            Log::add('OneSignal App ID o REST Key no están configurados.', Log::ERROR, 'onesignal-plugin');
            return false;
        }

        // Paso 2: Definir el punto de acceso de la API de OneSignal
        $url = 'https://onesignal.com/api/v1/notifications';

        // Paso 3: Configurar la cabecera con autenticación básica
        $header = array(
            "Content-Type: application/json; charset=utf-8",
            'Authorization: Basic ' . $onesignal_rest_key
        );

        // Paso 4: Definir el encabezado y contenido de la notificación
        $headings = array($language => $message_title);
        $contents = array($language => $article_title);
        $categorias =  $this->getArticleCategoryName($article_title);

        // Paso 5: Dividir los segmentos en un array en caso que exista mas de un segmento definido
        $segments = explode(',', $segments);

        // Paso 6: Preparar los datos de la solicitud HTTP
        // determina si se muestran o no imagenes en la notificacion 
        $Imagesyesno = $this->params->get('Images', 1);

        // si se ha optado por mostrar la imagen en este paso si se muestra
        if ($Imagesyesno) {
            $data = array(
                'app_id' => $onesignal_app_id,
                'headings' => $headings,
                'contents' => $contents,
                'included_segments' => $segments,
                'url' => $article_link,
                'web_push_topic' => $categorias,
                'chrome_web_image' => $article_image, // Añadir la imagen del artículo
                'ios_sound' => 'default',
                'android_sound' => 'default'
            );
        } else {
            // si se ha optado por no mostra imagen aqui se procesa sin imagen.
            $data = array(
                'app_id' => $onesignal_app_id,
                'headings' => $headings,
                'contents' => $contents,
                'included_segments' => $segments,
                'url' => $article_link,
                'web_push_topic' => $categorias, // usada para anidar notificaciones
                'ios_sound' => 'default',
                'android_sound' => 'default'
            );
        }

        // Paso 7: Configurar las opciones de la solicitud HTTP usando cURL
        try {
            // Validar datos
            if (empty($data['app_id']) || empty($data['contents'])) {
                throw new Exception('Datos incompletos para la notificación.');
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE); // Verificar SSL en producción
            curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Configurar timeout

            $result = curl_exec($ch);
            if ($result === FALSE) {
                $error_msg = 'cURL error: ' . curl_error($ch) . ' (Error Code: ' . curl_errno($ch) . ')';
                throw new Exception($error_msg);
            }
            curl_close($ch);

            // Registrar solicitud y respuesta
            Log::add('Solicitud enviada a OneSignal: ' . json_encode($data), Log::INFO, 'onesignal-plugin');
            Log::add('Respuesta de OneSignal: ' . $result, Log::INFO, 'onesignal-plugin');

            return $result;
        } catch (Exception $e) {
            // Obtener una instancia del objeto de la aplicación de Joomla
            $application = JFactory::getApplication();

            // Agregar un mensaje a la cola de mensajes
            $application->enqueueMessage(JText::_('Error al enviar la notificación a OneSignal: ' . $e->getMessage()), 'message');

            Log::add('Error al enviar la notificación a OneSignal: ' . $e->getMessage(), Log::ERROR, 'onesignal-plugin');
            return false;
        }
    }
    /**
     * Genera el enlace amigable al artículo.
     *
     * @param object $article Objeto que representa el artículo.
     * @return string URL amigable del artículo.
     */
    private function getLinkToArticle($article)
    {
        // Paso 1: Obtener el ID del artículo recién guardado
        $articleId = $article->id;

        // Paso 2: Cargar el artículo usando el ID
        $articleTable = JTable::getInstance('content');
        if (!$articleTable->load($articleId)) {
            Log::add('No se pudo cargar el artículo con ID: ' . $articleId, Log::ERROR, 'onesignal-plugin');
            return '';
        }

        // Paso 3: Cargar la categoría del artículo
        $categoryTable = JTable::getInstance('category');
        if (!$categoryTable->load($articleTable->catid)) {
            Log::add('No se pudo cargar la categoría con ID: ' . $articleTable->catid, Log::ERROR, 'onesignal-plugin');
            return '';
        }

        // Paso 4: Obtener la ruta completa de la categoría
        $categoryPath = $categoryTable->path; // Asumiendo que 'path' contiene la ruta completa de la categoría

        // Paso 5: Generar el slug del artículo
        $slug = $articleTable->alias; // Alias del artículo

        // Paso 6: Construir la URL amigable
        $fullUrl = rtrim(JUri::root(), '/') . '/' . $categoryPath . '/' . $articleTable->id . '-' . $slug . '.html';

        // Paso 7: Devolver la URL amigable
        return $fullUrl;
    }
}
