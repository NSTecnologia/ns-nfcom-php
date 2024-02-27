<?php

require('./src/Compartilhados/Endpoints.php');
require('./src/Compartilhados/Parametros.php');
require('./src/Compartilhados/Genericos.php');

foreach (glob('./src/Requisicoes/_Genericos/*.php') as $filename) {
    include_once($filename);
}

require('./src/Requisicoes/NFCom/ConsStatusProcessamentoReqNFCom.php');
require('./src/Requisicoes/NFCom/DownloadReqNFCom.php');
require('./src/Retornos/NFCom/EmitirSincronoRetNFCom.php');



class NSNFCom {

    private $token;
    private $parametros;
    private $endpoints;
    private $genericos;

    public function __construct() {
        $this->parametros = new Parametros(1);
        $this->endpoints = new Endpoints;
        $this->genericos = new Genericos;
        $this->token = 'Seu_Token_Aqui';
    }

    // Esta funcao envia um conteudo para uma URL, em requisicoes do tipo POST
    private function enviaConteudoParaAPI($conteudoAEnviar, $url, $tpConteudo){

        //Inicializa cURL para uma URL->
        $ch = curl_init($url);

        //Marca que vai enviar por POST(1=SIM)->
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        //Passa um json para o campo de envio POST->
        curl_setopt($ch, CURLOPT_POSTFIELDS, $conteudoAEnviar);

        //Marca como tipo de arquivo enviado json
        if ($tpConteudo == 'json')
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'X-AUTH-TOKEN: ' . $this->token));
        else if ($tpConteudo == 'xml')
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml', 'X-AUTH-TOKEN: ' . $this->token));
        else
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/plain', 'X-AUTH-TOKEN: ' . $this->token));

        //Marca que vai receber string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //Inicia a conexao
        $result = curl_exec($ch);

        if (curl_error($ch)) {
            echo 'Erro na comunicacao: ' . '<br>';
            echo '<br>';
            echo '<pre>';
            var_dump(curl_getinfo($ch));
            echo '</pre>';
            echo '<br>';
            var_dump(curl_error($ch));
        }

        //Fecha a conexao
        curl_close($ch);

        return json_decode($result, true);
    }

    //Métodos específicos de NFCom
    public function emitirNFComSincrono($conteudo, $tpConteudo, $CNPJ, $tpDown, $tpAmb, $caminho, $exibeNaTela) {

        $modelo = '62';
        $statusEnvio = null;
        $statusConsulta = null;
        $statusDownload = null;
        $motivo = null;
        $nsNRec = null;
        $chNFCom = null;
        $cStat = null;
        $nProt = null;

        $this->genericos->gravarLinhaLog($modelo, '[EMISSAO_SINCRONA_INICIO]');

        $resposta = $this->emitirDocumento($modelo, $conteudo, $tpConteudo);
        $statusEnvio = $resposta['status'] ;

        if ($statusEnvio == 200 || $statusEnvio == -6){

            $nsNRec = $resposta['nsNRec'];

            // É necessário aguardar alguns milisegundos antes de consultar o status de processamento
            sleep($this->parametros->TEMPO_ESPERA);

            $consStatusProcessamentoReqNFCom = new ConsStatusProcessamentoReqNFCom();
            $consStatusProcessamentoReqNFCom->CNPJ = $CNPJ;
            $consStatusProcessamentoReqNFCom->nsNRec = $nsNRec;
            $consStatusProcessamentoReqNFCom->tpAmb = $tpAmb;

            $resposta = $this->consultarStatusProcessamento($modelo, $consStatusProcessamentoReqNFCom);
            $statusConsulta = $resposta['status'];

            // Testa se a consulta foi feita com sucesso (200)
            if ($statusConsulta == 200){

                $cStat = $resposta['cStat'];

                if ($cStat == 100 || $cStat == 150){

                    $chNFCom = $resposta['chNFCom'];
                    $nProt = $resposta['nProt'];
                    $motivo = $resposta['xMotivo'];

                    $downloadReqNFCom = new DownloadReqNFCom();
                    $downloadReqNFCom->chNFCom = $chNFCom;
                    $downloadReqNFCom->tpAmb = $tpAmb;
                    $downloadReqNFCom->tpDown = $tpDown;

                    $resposta = $this->downloadDocumentoESalvar($modelo, $downloadReqNFCom, $caminho, $chNFCom . '-NFe', $exibeNaTela);
                    $statusDownload = $resposta['status'];

                    if ($statusDownload != 200)
                     $motivo = $resposta['motivo'];
                }else{
                    $motivo = $resposta['xMotivo'];
                    $chNFCom = $resposta['chNFCom'];
                }
            }else if ($statusConsulta == -2) {

                $cStat = $resposta['cStat'];
                $motivo = $resposta['erro']['xMotivo'];

            }else{
                $motivo = $resposta['motivo'];
            }
        }
        else if ($statusEnvio == -7){

            $motivo = $resposta['motivo'];
            $nsNRec = $resposta['nsNRec'];

        }
        else if ($statusEnvio == -4 || $statusEnvio == -2) {

            $motivo = $resposta['motivo'];
            $erros = $resposta['erros'];

        }
        else if ($statusEnvio == -999 || $statusEnvio == -5) {

            $motivo = $resposta['erro']['xMotivo'];

        }
        else {
            try {
                $motivo = $resposta['motivo'];
            }catch (Exception $ex){
                $motivo = $resposta;
            }
        }

        $emitirSincronoRetNFCom = new EmitirSincronoRetNFCom();
        $emitirSincronoRetNFCom->statusEnvio = $statusEnvio;
        $emitirSincronoRetNFCom->statusConsulta = $statusConsulta;
        $emitirSincronoRetNFCom->statusDownload = $statusDownload;
        $emitirSincronoRetNFCom->cStat = $cStat;
        $emitirSincronoRetNFCom->chNFCom = $chNFCom;
        $emitirSincronoRetNFCom->nProt = $nProt;
        $emitirSincronoRetNFCom->motivo = $motivo;
        $emitirSincronoRetNFCom->nsNRec = $nsNRec;
        $emitirSincronoRetNFCom->erros = $erros;

        $emitirSincronoRetNFCom = array_filter((array) $emitirSincronoRetNFCom);

        $retorno = json_encode($emitirSincronoRetNFCom, JSON_UNESCAPED_UNICODE);

        $this->genericos->gravarLinhaLog($modelo, '[JSON_RETORNO]');
        $this->genericos->gravarLinhaLog($modelo, $retorno);
        $this->genericos->gravarLinhaLog($modelo, '[EMISSAO_SINCRONA_FIM]');

        return $retorno;
    }
    

    // Métodos genéricos, compartilhados entre diversas funções
    public function emitirDocumento($modelo, $conteudo, $tpConteudo){

        switch($modelo){
        
            case '62':
                $urlEnvio = $this->endpoints->NFComEnvio;
                break;
            
            default:
                throw new Exception('Não definido endpoint de envio para o modelo ' . $modelo);
        }

        $this->genericos->gravarLinhaLog($modelo, '[ENVIA_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $conteudo);

        $resposta = $this->enviaConteudoParaAPI($conteudo, $urlEnvio, $tpConteudo);

        $this->genericos->gravarLinhaLog($modelo, '[ENVIA_RESPOSTA]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));

        return $resposta;
    }

    public function consultarStatusProcessamento($modelo, $consStatusProcessamentoReq){
        switch ($modelo) {

            case '62':
                $urlConsulta = $this->endpoints->NFComConsStatusProcessamento;
                break;

            default:
                throw new Exception('Não definido endpoint de consulta para o modelo ' . $modelo);
        }

        $json = json_encode((array) $consStatusProcessamentoReq, JSON_UNESCAPED_UNICODE);

        $this->genericos->gravarLinhaLog($modelo, '[CONSULTA_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);

        $resposta = $this->enviaConteudoParaAPI($json, $urlConsulta, 'json');

        $this->genericos->gravarLinhaLog($modelo, '[CONSULTA_RESPOSTA]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));

        return $resposta;
    }

    public function downloadDocumento($modelo, $downloadReq){
        switch ($modelo) {

            case '62':
                $urlDownload = $this->endpoints->NFComDownload;
                break;

            default:
                throw new Exception('Não definido endpoint de Download para o modelo ' . $modelo);
        }

        $json = json_encode((array) $downloadReq, JSON_UNESCAPED_UNICODE);

        $this->genericos->gravarLinhaLog($modelo, '[DOWNLOAD_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);

        $resposta = $this->enviaConteudoParaAPI($json, $urlDownload, 'json');
        $status = $resposta['status'];

        if(($status != 200) || ($status != 100)){
            $this->genericos->gravarLinhaLog($modelo, '[DOWNLOAD_RESPOSTA]');
            $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));
        }else{
            $this->genericos->gravarLinhaLog($modelo, '[DOWNLOAD_STATUS]');
            $this->genericos->gravarLinhaLog($modelo, $status);
        }
        return $resposta;
    }

    public function downloadDocumentoESalvar($modelo, $downloadReq, $caminho, $nome, $exibeNaTela){

        $resposta = $this->downloadDocumento($modelo, $downloadReq);
        $status = $resposta['status'];
        if (($status == 200) || ($status == 100)) {
            try{
                if (strlen($caminho) > 0) if (!file_exists($caminho)) mkdir($caminho, 0777, true);
                if(substr($caminho, -1) != '/') $caminho= $caminho . '/';
            }catch(Exception $e){
                $this->genericos->gravarLinhaLog($modelo, '[CRIA_DIRETORIO] '+ $caminho);
                $this->genericos->gravarLinhaLog($modelo, $e->getMessage());
                throw new Exception('Exceção capturada: ' + $e->getMessage());
            }

            if ($modelo == '62') {

                if (strpos(strtoupper($downloadReq->tpDown), 'X') >= 0) {
                    $xml = $resposta['xml'];
                    $this->genericos->salvaXML($xml, $caminho, $nome);
                }
            } 
        }
        return $resposta;
    }

    public function listarNSNRecs($modelo, $listarNSNRecReq) {

        switch ($modelo){
         
            case '62':
                $urlListarNSNRecs = $this->endpoints->NFComListarNSNRecs;
                break;

            default:
                throw new Exception('Não definido endpoint de listagem de nsNRec para o modelo ' . $modelo);
        }

        $json = json_encode((array) $listarNSNRecReq, JSON_UNESCAPED_UNICODE);

        $this->genericos->gravarLinhaLog($modelo, '[LISTAR_NSNRECS_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);

        $resposta = $this->enviaConteudoParaAPI($json, $urlListarNSNRecs, 'json');

        $this->genericos->gravarLinhaLog($modelo, '[LISTAR_NSNRECS_RESPOSTA]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));

        return $resposta;
    }

}
?>
