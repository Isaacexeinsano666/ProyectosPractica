const gravedad = 9.81;
let altura = 1;

const fluidos = {
    agua: {
        nombre: "Agua",
        densidad: 1000,
        color: "#6495ed"
    },
    aceite: {
        nombre: "Aceite",
        densidad: 850,
        color: "#e5b94c"
    },
    mercurio: {
        nombre: "Mercurio",
        densidad: 13546,
        color: "#9aa0a6"
    }
};

function calcularPresion(claveLiquido) {
    const liquido = fluidos[claveLiquido];
    const presion = liquido.densidad * gravedad * altura;
    return presion;
}