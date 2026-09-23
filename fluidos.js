const gravedad = 9.8;
let altura = 1;

const alturaMinima = 0.1;
const alturaMaxima = 2;

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
    },
    alcohol: {
        nombre: "Alcohol",
        densidad: 789,
        color: "#c5d4e2"
    },
    glicerina: {
        nombre: "Glicerina",
        densidad: 1260,
        color: "#d69213"
    },
    parafina: {
        nombre: "Parafina",
        densidad: 810,
        color: "#b1a996"
    },
    gasolina: {
        nombre: "Gasolina",
        densidad: 720,
        color: "#fcd572"
    }
    
};

function calcularPresion(claveLiquido) {
    const liquido = fluidos[claveLiquido];
    const presion = liquido.densidad * gravedad * altura;
    return presion;
}