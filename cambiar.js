function cambiar() {

    if (liquidoActual === "agua") {
        liquidoActual = "aceite";

    } else if (liquidoActual === "aceite") {
        liquidoActual = "mercurio";

    } else {
        liquidoActual = "agua";
    }

    const liquido = liquidos[liquidoActual];

    document.querySelector(".liquido").style.backgroundColor =
        liquido.color;

    document.querySelector(".nombre-liquido").textContent =
        liquido.nombre;
        
    console.log("Densidad:", liquido.densidad);
}