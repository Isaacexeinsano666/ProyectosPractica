function comparar() {

    const keyVaso1 = document.getElementById("selectLiquido1").value;
    const keyVaso2 = document.getElementById("selectLiquido2").value;

    const liq1 = fluidos[keyVaso1];
    const liq2 = fluidos[keyVaso2];

    document.getElementById("fill1").style.backgroundColor = liq1.color;
    document.getElementById("fill2").style.backgroundColor = liq2.color;

    document.getElementById("nombreLiquido1").textContent = liq1.nombre;
    document.getElementById("nombreLiquido2").textContent = liq2.nombre;

    const presion1 = calcularPresion(keyVaso1);
    const presion2 = calcularPresion(keyVaso2);

    const presionDividida1 = presion1 / 1000;
    const presionDividida2 = presion2 / 1000;

    document.getElementById("presion1").innerHTML =
        `Presión: ${presion1.toFixed(2)} Pa<br>
         Pa ÷ 1000 = ${presionDividida1.toFixed(2)}`;

    document.getElementById("presion2").innerHTML =
        `Presión: ${presion2.toFixed(2)} Pa<br>
         Pa ÷ 1000 = ${presionDividida2.toFixed(2)}`;

    actualizarAlturaLiquido();

    actualizarPelotas();
}

function cambiarAltura(valor) {

    altura = parseFloat(valor);

    document.getElementById("valorAltura").textContent =
        altura.toFixed(2);

    actualizarAlturaLiquido();

    comparar();
}

function actualizarAlturaLiquido() {

    const alturaMinima = 0.1;
    const alturaMaxima = 2;

    const porcentaje = ((altura - alturaMinima) /
        (alturaMaxima - alturaMinima)) * 100;

    document.getElementById("fill1").style.height = `${porcentaje}%`;
    document.getElementById("fill2").style.height = `${porcentaje}%`;
}

function actualizarPelotas() {

    const keyVaso1 = document.getElementById("selectLiquido1").value;
    const keyVaso2 = document.getElementById("selectLiquido2").value;

    const liq1 = fluidos[keyVaso1];
    const liq2 = fluidos[keyVaso2];

    const densidadMax = 13546;

    const pos1 = 85 - ((liq1.densidad / densidadMax) * 80);
    const pos2 = 85 - ((liq2.densidad / densidadMax) * 80);

    document.getElementById("ball1").style.top = `${pos1}%`;
    document.getElementById("ball2").style.top = `${pos2}%`;
}

window.onload = comparar;