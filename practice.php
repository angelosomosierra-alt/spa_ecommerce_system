<?php



class car{
    public $color;
    public $brand;



    function __construct ($color, $brand){
        $this->color = $color;
        $this->brand = $brand;
    }

    function showcar(){
        echo "color: " . $this->color . "| brand: " . $this->brand;  
    }
}

    $car1 = new car("red", "honda");
    $car2 = new car("blue", "bmw");

    
echo $car1->showcar();
echo $car2->showcar();
?>