<?php

header("Content-Type: application/json");

$folder="txt/";

if(!is_dir($folder)){
echo json_encode([]);
exit;
}

$files=glob($folder."*.txt");

$libros=[];

foreach($files as $file){

$contenido=file_get_contents($file);

if($contenido){
$libros[]=[
"archivo"=>basename($file),
"contenido"=>$contenido
];
}

}

echo json_encode($libros);

?>