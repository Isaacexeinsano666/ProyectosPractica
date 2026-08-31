function cambiar() {

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

    document.getElementById("presion1").textContent = `Presión: ${presion1.toFixed(2)} Pa`;
    document.getElementById("presion2").textContent = `Presión: ${presion2.toFixed(2)} Pa`;

    console.clear();
    console.log(`Vaso 1 (${liq1.nombre}): ${presion1.toFixed(2)} Pa | Densidad: ${liq1.densidad} kg/m³`);
    console.log(`Vaso 2 (${liq2.nombre}): ${presion2.toFixed(2)} Pa | Densidad: ${liq2.densidad} kg/m³`);
}